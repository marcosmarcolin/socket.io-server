<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Marcolin\SocketIO\Engine\Transport\HttpExchange;
use Marcolin\SocketIO\Engine\Transport\Polling;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Marcolin\SocketIO\Engine\Transport\WebSocket;
use Marcolin\SocketIO\Engine\Transport\WebSocketConnection;
use Marcolin\SocketIO\Event\Emitter;

/**
 * The Engine.IO server: validates requests, opens sessions and routes requests to them.
 *
 * It knows nothing about Workerman; the network side calls handleRequest() and handleWebSocket().
 *
 * Events:
 * - `connection` (Socket): a session was opened.
 */
final class Server extends Emitter
{
    private const TRANSPORTS = ['polling', 'websocket'];

    /** @var array<string, Socket> */
    private array $sessions = [];

    public function __construct(
        public readonly ServerOptions $options,
        private readonly Scheduler $scheduler,
        private readonly SessionIdGenerator $ids = new RandomSessionIdGenerator(),
    ) {
    }

    /**
     * Whether a request path belongs to this server.
     */
    public function handles(string $path): bool
    {
        return str_starts_with($path, $this->options->path) || $path . '/' === $this->options->path;
    }

    public function handleRequest(HttpExchange $exchange): void
    {
        if (! $this->handles($exchange->path())) {
            $exchange->respond(404, '');

            return;
        }

        $cors = $this->options->cors;
        if ($cors !== null) {
            $exchange = new CorsHttpExchange($exchange, $cors);
            if ($exchange->method() === 'OPTIONS') {
                $exchange->respond(204, '', $cors->preflightHeaders(
                    $exchange->header('Origin'),
                    $exchange->header('Access-Control-Request-Headers'),
                ));

                return;
            }
        }

        $error = $this->verify(
            $exchange->query('transport'),
            $exchange->query('sid'),
            $exchange->query('EIO'),
            $exchange->header('Origin'),
            $exchange->method(),
            false,
        );
        if ($error !== null) {
            $this->reject($exchange, $error);

            return;
        }

        $sid = $exchange->query('sid');
        if ($sid !== null) {
            $transport = $this->sessions[$sid]->transport();
            // verify() matched the transport name, so this is a safety net.
            if (! $transport instanceof Polling) {
                $this->reject($exchange, ServerError::BadRequest);

                return;
            }
            $transport->handleRequest($exchange);

            return;
        }

        $polling = new Polling($this->scheduler, maxPayload: $this->options->maxPayload);
        $socket = $this->open($polling, $exchange->request());
        $polling->handleRequest($exchange);
        $this->dispatch('connection', $socket);
    }

    /**
     * Checks a WebSocket request before the handshake is answered.
     *
     * @return ServerError|null null when the connection can be accepted
     */
    public function verifyWebSocket(Request $request): ?ServerError
    {
        if (! $this->handles($request->path)) {
            return ServerError::BadRequest;
        }

        return $this->verify(
            $request->query('transport'),
            $request->query('sid'),
            $request->query('EIO'),
            $request->header('Origin'),
            'GET',
            true,
        );
    }

    /**
     * Takes an accepted WebSocket connection: a new session, or an upgrade of a polling one.
     */
    public function handleWebSocket(Request $request, WebSocketConnection $connection): void
    {
        // The session may have closed since verifyWebSocket().
        if ($this->verifyWebSocket($request) !== null) {
            $connection->close();

            return;
        }

        $transport = new WebSocket($connection);
        $sid = $request->query('sid');
        if ($sid !== null) {
            $this->sessions[$sid]->maybeUpgrade($transport);

            return;
        }

        $this->dispatch('connection', $this->open($transport, $request));
    }

    public function session(string $sid): ?Socket
    {
        return $this->sessions[$sid] ?? null;
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    /**
     * Closes every session.
     */
    public function close(): void
    {
        foreach ($this->sessions as $socket) {
            $socket->close();
        }
    }

    private function verify(
        ?string $transport,
        ?string $sid,
        ?string $protocol,
        ?string $origin,
        string $method,
        bool $upgrade,
    ): ?ServerError {
        if ($transport === null || ! in_array($transport, self::TRANSPORTS, true)) {
            return ServerError::UnknownTransport;
        }
        // The header would break the response, as in Node's checkInvalidHeaderChar().
        if ($origin !== null && preg_match('/[^\t\x20-\x7e\x80-\xff]/', $origin) === 1) {
            return ServerError::BadRequest;
        }

        if ($sid !== null) {
            $socket = $this->sessions[$sid] ?? null;
            if ($socket === null) {
                return ServerError::UnknownSid;
            }
            if ($upgrade ? $transport !== 'websocket' : $socket->transport()->name() !== $transport) {
                return ServerError::BadRequest;
            }

            return $protocol === '4' ? null : ServerError::BadRequest;
        }

        if ($method !== 'GET') {
            return ServerError::BadHandshakeMethod;
        }
        if ($transport === 'websocket' && ! $upgrade) {
            return ServerError::BadRequest;
        }
        if ($transport === 'polling' && $upgrade) {
            return ServerError::BadRequest;
        }

        return $protocol === '4' ? null : ServerError::UnsupportedProtocolVersion;
    }

    private function reject(HttpExchange $exchange, ServerError $error): void
    {
        $exchange->respond($error->httpStatus(), $error->body(), ['Content-Type' => 'application/json']);
    }

    private function open(Transport $transport, Request $request): Socket
    {
        $id = $this->ids->generate();
        $socket = new Socket($id, $transport, $this->options, $this->scheduler, $request);
        $this->sessions[$id] = $socket;
        $socket->once('close', function () use ($id): void {
            unset($this->sessions[$id]);
        });

        return $socket;
    }
}
