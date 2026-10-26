<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Protocol\HttpOrWebSocket;
use Marcolin\SocketIO\Engine\Protocol\WebSocket;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\Rejection;
use Marcolin\SocketIO\Engine\Request as EngineRequest;
use Throwable;
use WeakMap;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

/**
 * Connects a Workerman worker to an Engine.IO server: HTTP and WebSocket on the worker's port.
 *
 * Exceptions never leave a callback: Workerman stops the worker when one escapes a WebSocket handshake callback.
 * The connection is closed instead, and the exception goes to the error handler.
 */
final class ServerBinding
{
    // Workerman counts the headers in the HTTP package size; this is its header limit.
    private const HTTP_HEADER_ALLOWANCE = 16_384;

    // The largest WebSocket frame header, counted in the frame size.
    private const FRAME_HEADER_ALLOWANCE = 14;

    /** @var WeakMap<TcpConnection, WorkermanHttpExchange> the last request on each HTTP connection */
    private WeakMap $requests;

    /** @var WeakMap<TcpConnection, WorkermanWebSocketConnection> */
    private WeakMap $webSockets;

    /** @var WeakMap<TcpConnection, EngineRequest> the request accepted before the 101, with what middlewares attached */
    private WeakMap $webSocketRequests;

    /** @var Closure(Throwable): void */
    private readonly Closure $onError;

    // The WebSocket connections' frames, written once per pass of the event loop.
    private readonly WriteGroup $writeGroup;

    /**
     * @param (Closure(Throwable): void)|null $onError receives exceptions from the callbacks; Worker::log by default
     */
    public function __construct(private readonly Server $server, ?Closure $onError = null)
    {
        $this->requests = new WeakMap();
        $this->webSockets = new WeakMap();
        $this->webSocketRequests = new WeakMap();
        $this->writeGroup = new WriteGroup($server->scheduler());
        $this->onError = $onError ?? static function (Throwable $error): void {
            Worker::log((string) $error);
        };
    }

    /**
     * Takes over the worker's protocol and connection callbacks. Call it before Worker::runAll().
     */
    public function bind(Worker $worker): void
    {
        $worker->protocol = HttpOrWebSocket::class;
        $worker->onConnect = $this->onConnect(...);
        $worker->onMessage = $this->onMessage(...);
        $worker->onWebSocketConnect = $this->onWebSocketConnect(...);
        $worker->onWebSocketConnected = $this->onWebSocketConnected(...);
        $worker->onClose = $this->onClose(...);
    }

    public function onConnect(TcpConnection $connection): void
    {
        $connection->maxPackageSize = $this->server->options->maxPayload + self::HTTP_HEADER_ALLOWANCE;
    }

    public function onMessage(TcpConnection $connection, mixed $data): void
    {
        $webSocket = $this->webSockets[$connection] ?? null;
        if ($webSocket !== null) {
            if (is_string($data)) {
                $this->guard($connection, static function () use ($webSocket, $data, $connection): void {
                    $webSocket->receive($data, WebSocket::isBinary($connection));
                });
            }

            return;
        }
        if (! $data instanceof Request) {
            return;
        }

        $exchange = new WorkermanHttpExchange($connection, $data);
        $this->requests[$connection] = $exchange;
        $this->guard(
            $connection,
            function () use ($exchange): void {
                $this->server->handleRequest($exchange);
            },
            static function () use ($exchange): void {
                $exchange->respond(500, '');
            },
        );
    }

    /**
     * Runs before the handshake is answered, so an invalid request gets an HTTP error instead of a WebSocket.
     */
    public function onWebSocketConnect(TcpConnection $connection, Request $request): void
    {
        $this->guard($connection, function () use ($connection, $request): void {
            $engineRequest = RequestValues::request($connection, $request);
            $rejection = $this->server->verifyWebSocket($engineRequest);
            if ($rejection !== null) {
                self::reject($connection, $rejection);

                return;
            }
            $this->webSocketRequests[$connection] = $engineRequest;
            // Workerman writes these lines into the 101.
            foreach ($this->server->webSocketHeaders($engineRequest) as $name => $values) {
                foreach (is_string($values) ? [$values] : $values as $value) {
                    $connection->headers[] = $name . ': ' . $value;
                }
            }
            $protocol = $this->server->webSocketProtocol($engineRequest);
            if ($protocol !== null) {
                $connection->headers[] = 'Sec-WebSocket-Protocol: ' . $protocol;
            }
            // Accepting the extension in the 101 is what turns on Workerman's deflate for this connection.
            $extension = $this->server->webSocketExtension($engineRequest);
            if ($extension !== null) {
                $connection->headers[] = 'Sec-WebSocket-Extensions: ' . $extension;
            }
        });
    }

    /**
     * Workerman calls this even after onWebSocketConnect rejected the request, so a closed connection is skipped.
     */
    public function onWebSocketConnected(TcpConnection $connection, Request $request): void
    {
        if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return;
        }
        $connection->maxPackageSize = $this->server->options->maxPayload + self::FRAME_HEADER_ALLOWANCE;

        $webSocket = new WorkermanWebSocketConnection($connection, $this->writeGroup);
        $this->webSockets[$connection] = $webSocket;
        $engineRequest = $this->webSocketRequests[$connection] ?? RequestValues::request($connection, $request);
        unset($this->webSocketRequests[$connection]);
        $this->guard($connection, function () use ($engineRequest, $webSocket): void {
            $this->server->handleWebSocket($engineRequest, $webSocket);
        });
    }

    public function onClose(TcpConnection $connection): void
    {
        $webSocket = $this->webSockets[$connection] ?? null;
        $request = $this->requests[$connection] ?? null;
        unset($this->webSockets[$connection], $this->requests[$connection]);

        $this->guard($connection, static function () use ($webSocket, $request): void {
            $webSocket?->closed();
            $request?->abort();
        });
    }

    /**
     * @param Closure(): void $work
     * @param (Closure(): void)|null $beforeClose runs when $work throws, before the connection is closed
     */
    private function guard(TcpConnection $connection, Closure $work, ?Closure $beforeClose = null): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            try {
                if ($beforeClose !== null) {
                    $beforeClose();
                }
                // What the handler sent before it threw goes out first, as it did before writes were grouped.
                ($this->webSockets[$connection] ?? null)?->flush();
                $connection->close();
            } finally {
                ($this->onError)($e);
            }
        }
    }

    /**
     * Answers a WebSocket handshake with an HTTP error, as the reference does.
     */
    private static function reject(TcpConnection $connection, Rejection $rejection): void
    {
        $message = $rejection->message;
        $connection->close(
            "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Type: text/html\r\n"
            . 'Content-Length: ' . strlen($message) . "\r\n\r\n" . $message,
            true,
        );
    }
}
