<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;
use Marcolin\SocketIO\Engine\Transport\Polling;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Marcolin\SocketIO\Engine\Transport\WebSocket;
use Marcolin\SocketIO\Engine\Transport\WebSocketConnection;
use Marcolin\SocketIO\Event\Emitter;
use Throwable;

/**
 * The Engine.IO server: validates requests, opens sessions and routes requests to them.
 *
 * It knows nothing about Workerman; the network side calls handleRequest() and handleWebSocket().
 *
 * Events, as in the reference:
 * - `connection` (Socket): a session was opened;
 * - `initial_headers` (ResponseHeaders, Request): the headers of a session's first answer, the handshake over
 *   polling or the 101 of a WebSocket without sid, which listeners may change, such as to set a cookie;
 * - `headers` (ResponseHeaders, Request): the same for every successful polling answer and every 101, after
 *   `initial_headers`;
 * - `connection_error` (Request, int $code, string $message): a request refused, with its Engine.IO error code
 *   and the message sent to the client.
 *
 * use() adds middlewares for every request, as the reference's engine.use().
 */
final class Server extends Emitter
{
    /** @var array<string, Socket> */
    private array $sessions = [];

    /** @var list<Closure(Request, Closure(?Throwable=): void): void> */
    private array $middlewares = [];

    public function __construct(
        public readonly ServerOptions $options,
        private readonly Scheduler $scheduler,
        private readonly SessionIdGenerator $ids = new RandomSessionIdGenerator(),
    ) {
    }

    /**
     * Adds a middleware for every HTTP request of the server, polling ones and the request that opens a WebSocket,
     * with a sid or not, as the reference's engine.use(). It runs before the request is validated, and may attach
     * data to $request->attributes, which the session keeps on $socket->request when the request opened it.
     *
     * It calls $next() to let the request through, or $next($error) to refuse it with code 3 (Bad request), as in
     * the reference. Unlike Node, $next must be called before the middleware returns, since a WebSocket handshake
     * is answered at once: a middleware that returns without calling it refuses the request too. An exception it
     * throws propagates.
     *
     * @param Closure(Request, Closure(?Throwable=): void): void $middleware
     */
    public function use(Closure $middleware): static
    {
        $this->middlewares[] = $middleware;

        return $this;
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
                $exchange->respond(204, '', $cors->preflightHeaders($exchange->request()));

                return;
            }
        }

        $rejection = $this->applyMiddlewares($exchange->request());
        if ($rejection !== null) {
            $this->reject($exchange, $rejection);

            return;
        }
        $this->expireIfOverdue($exchange->request());
        $rejection = $this->verify($exchange->request(), $exchange->method(), false, true);
        if ($rejection !== null) {
            $this->reject($exchange, $rejection);

            return;
        }
        $sid = $exchange->query('sid');
        if ($sid !== null && $this->sessions[$sid]->isUpgrading()) {
            // The client may have sent the upgrade's "5" in this same pass of the event loop, and Workerman's
            // select() reads sockets in descriptor order, not arrival order: route it once that is read.
            $aborted = false;
            $exchange->onAbort(static function () use (&$aborted): void {
                $aborted = true;
            });
            $this->scheduler->after(0, function () use ($exchange, &$aborted): void {
                if (! $aborted) {
                    $this->routeToSession($exchange, true);
                }
            });

            return;
        }
        if ($sid !== null) {
            $this->routeToSession($exchange, false);

            return;
        }

        $polling = new Polling(
            $this->scheduler,
            maxPayload: $this->options->maxPayload,
            compressionThreshold: $this->options->httpCompression,
        );
        $socket = $this->open($polling, $exchange->request());
        $cookie = $this->options->cookie;
        $polling->handleRequest(new HeadersHttpExchange(
            $exchange,
            function (ResponseHeaders $headers, Request $request) use ($cookie, $socket): void {
                // Before initial_headers, so its listeners see the cookie, as in the reference.
                if ($cookie !== null) {
                    $headers->append('Set-Cookie', $cookie->header($socket->id));
                }
                $this->prepareHeaders($headers, $request);
            },
        ));
        $this->dispatch('connection', $socket);
    }


    /**
     * Hands a request with a sid to its session's polling transport.
     *
     * @param bool $waited verifies it again: the session may have closed or upgraded meanwhile
     */
    private function routeToSession(HttpExchange $exchange, bool $waited): void
    {
        $rejection = $waited ? $this->verify($exchange->request(), $exchange->method(), false, true) : null;
        if ($rejection !== null) {
            $this->reject($exchange, $rejection);

            return;
        }
        $transport = $this->sessions[(string) $exchange->query('sid')]->transport();
        // verify() matched the transport name, so this is a safety net.
        if (! $transport instanceof Polling) {
            $this->reject($exchange, new Rejection(ServerError::BadRequest));

            return;
        }
        $transport->handleRequest(new HeadersHttpExchange($exchange, $this->prepareHeaders(...)));
    }

    /**
     * Checks a WebSocket request before the handshake is answered.
     *
     * @return Rejection|null null when the connection can be accepted
     */
    public function verifyWebSocket(Request $request): ?Rejection
    {
        $rejection = $this->applyMiddlewares($request);
        if ($rejection === null) {
            $this->expireIfOverdue($request);
            $rejection = $this->verifyWebSocketRequest($request, true);
        }
        if ($rejection !== null) {
            $this->dispatch('connection_error', $request, $rejection->error->value, $rejection->message);
        }

        return $rejection;
    }

    /**
     * The extra headers of the 101 that accepts a WebSocket, from the `initial_headers` and `headers` listeners.
     *
     * @return array<string, string|list<string>>
     */
    public function webSocketHeaders(Request $request): array
    {
        $headers = new ResponseHeaders();
        $this->prepareHeaders($headers, $request);

        return $headers->all();
    }

    /**
     * Takes an accepted WebSocket connection: a new session, or an upgrade of a polling one.
     */
    public function handleWebSocket(Request $request, WebSocketConnection $connection): void
    {
        // The session may have closed since verifyWebSocket(); allowRequest already answered, so it isn't asked again.
        if ($this->verifyWebSocketRequest($request, false) !== null) {
            $connection->close();

            return;
        }

        $deflate = $this->webSocketExtension($request) !== null ? $this->options->perMessageDeflate : null;
        // A client with forceBase64 asks with b64, as in the reference: any non-empty value.
        $transport = new WebSocket($connection, $deflate, ($request->query('b64') ?? '') === '');
        $sid = $request->query('sid');
        if ($sid !== null) {
            $this->sessions[$sid]->maybeUpgrade($transport);

            return;
        }

        $this->dispatch('connection', $this->open($transport, $request));
    }

    /**
     * The Sec-WebSocket-Protocol value of the 101: the first subprotocol the client offers, as the reference's ws
     * picks by default, or null when it offers none. A browser that offered some refuses a 101 without one.
     */
    public function webSocketProtocol(Request $request): ?string
    {
        $first = trim(explode(',', $request->header('Sec-WebSocket-Protocol') ?? '')[0]);

        // A token, as RFC 6455 requires; anything else would break the header.
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $first) === 1 ? $first : null;
    }

    /**
     * The Sec-WebSocket-Extensions value of the 101: permessage-deflate when the option is on and the client
     * offers it with parameters the server can honor, or null.
     *
     * The deflate context is kept across messages with a 15-bit window, so offers that ask the server to reset
     * it (server_no_context_takeover) or to use a smaller window are declined, as RFC 7692 allows.
     */
    public function webSocketExtension(Request $request): ?string
    {
        if ($this->options->perMessageDeflate === null) {
            return null;
        }
        foreach (explode(',', $request->header('Sec-WebSocket-Extensions') ?? '') as $offer) {
            $parameters = array_map('trim', explode(';', $offer));
            if (strtolower((string) array_shift($parameters)) !== 'permessage-deflate') {
                continue;
            }
            $acceptable = true;
            foreach ($parameters as $parameter) {
                $pair = explode('=', $parameter, 2);
                $value = trim($pair[1] ?? '', " \t\"");
                $acceptable = $acceptable && match (strtolower(trim($pair[0]))) {
                    'client_max_window_bits', 'client_no_context_takeover' => true,
                    'server_max_window_bits' => $value === '15',
                    default => false,
                };
            }
            if ($acceptable) {
                return 'permessage-deflate';
            }
        }

        return null;
    }

    /**
     * Gives the request's session a chance to close first if its ping timeout already ended, as the reference's
     * event loop would have, so the request finds it gone.
     */
    private function expireIfOverdue(Request $request): void
    {
        $sid = $request->query('sid');
        if ($sid !== null) {
            ($this->sessions[$sid] ?? null)?->expireIfOverdue();
        }
    }

    public function session(string $sid): ?Socket
    {
        return $this->sessions[$sid] ?? null;
    }

    /**
     * @internal for the network side, such as the WebSocket connections that group their writes
     */
    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    /**
     * How many sessions are open, as the reference's clientsCount.
     */
    public function clientsCount(): int
    {
        return count($this->sessions);
    }

    /**
     * Closes every session at once, as the reference does when the server shuts down.
     */
    public function close(): void
    {
        foreach ($this->sessions as $socket) {
            $socket->close(true);
        }
    }

    private function verifyWebSocketRequest(Request $request, bool $askApplication): ?Rejection
    {
        if (! $this->handles($request->path)) {
            return new Rejection(ServerError::BadRequest);
        }

        return $this->verify($request, 'GET', true, $askApplication);
    }

    /**
     * Validates a request in the reference's order.
     *
     * @param bool $askApplication whether a handshake goes through the allowRequest option
     */
    private function verify(Request $request, string $method, bool $upgrade, bool $askApplication): ?Rejection
    {
        $transport = $request->query('transport');
        // A transport the options leave out is as unknown as a misspelled one, as in the reference.
        if ($transport === null || ! in_array($transport, $this->options->transports, true)) {
            return new Rejection(ServerError::UnknownTransport);
        }
        // The header would break the response, as in Node's checkInvalidHeaderChar().
        $origin = $request->header('Origin');
        if ($origin !== null && preg_match('/[^\t\x20-\x7e\x80-\xff]/', $origin) === 1) {
            return new Rejection(ServerError::BadRequest);
        }
        $protocol = $request->query('EIO');

        $sid = $request->query('sid');
        if ($sid !== null) {
            $socket = $this->sessions[$sid] ?? null;
            if ($socket === null) {
                return new Rejection(ServerError::UnknownSid);
            }
            if ($upgrade ? $transport !== 'websocket' : $socket->transport()->name() !== $transport) {
                return new Rejection(ServerError::BadRequest);
            }

            return $protocol === '4' ? null : new Rejection(ServerError::BadRequest);
        }

        if ($method !== 'GET') {
            return new Rejection(ServerError::BadHandshakeMethod);
        }
        if (($transport === 'websocket') !== $upgrade) {
            return new Rejection(ServerError::BadRequest);
        }
        // The reference asks the application before it checks the protocol version.
        $rejection = $askApplication ? $this->askApplication($request) : null;
        if ($rejection !== null) {
            return $rejection;
        }

        return $protocol === '4' ? null : new Rejection(ServerError::UnsupportedProtocolVersion);
    }

    private function askApplication(Request $request): ?Rejection
    {
        $allowRequest = $this->options->allowRequest;
        if ($allowRequest === null) {
            return null;
        }
        $answer = $allowRequest($request);
        if ($answer === true) {
            return null;
        }

        // An empty message falls back to the default one, as in the reference.
        return new Rejection(ServerError::Forbidden, is_string($answer) && $answer !== '' ? $answer : null);
    }

    /**
     * Runs the middlewares in order; null when every one let the request through.
     */
    private function applyMiddlewares(Request $request): ?Rejection
    {
        foreach ($this->middlewares as $middleware) {
            $passed = false;
            $middleware($request, static function (?Throwable $error = null) use (&$passed): void {
                $passed = $error === null;
            });
            if (! $passed) {
                return new Rejection(ServerError::BadRequest);
            }
        }

        return null;
    }

    private function reject(HttpExchange $exchange, Rejection $rejection): void
    {
        $this->dispatch('connection_error', $exchange->request(), $rejection->error->value, $rejection->message);
        $exchange->respond($rejection->httpStatus(), $rejection->body(), ['Content-Type' => 'application/json']);
    }

    private function prepareHeaders(ResponseHeaders $headers, Request $request): void
    {
        if ($request->query('sid') === null) {
            $this->dispatch('initial_headers', $headers, $request);
        }
        $this->dispatch('headers', $headers, $request);
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
