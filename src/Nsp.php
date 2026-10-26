<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Event\Emitter;
use Throwable;

/**
 * A namespace.
 *
 * Events: `connection` and `connect` (Socket), when a socket joined, after the middlewares let it in; on the main
 * namespace, `new_namespace` (Nsp) when another namespace is created; and the events other servers send with
 * serverSideEmit().
 *
 * Broadcasts, socketsJoin, socketsLeave and disconnectSockets reach every server through the adapter, and so does
 * fetchSockets with a callback; allSockets and fetchSockets without one read this process.
 *
 * @property-read BroadcastOperator $volatile broadcasts that skip sockets whose transport is busy
 * @property-read BroadcastOperator $local broadcasts and socket helpers for this process only
 */
final class Nsp extends Emitter
{
    private Adapter $adapter;

    /** @var array<string, Socket> connected sockets by id */
    private array $sockets = [];

    /** @var array<string, Socket> sockets still going through the middlewares */
    private array $connecting = [];

    /** @var list<Closure(Socket, Closure(?Throwable=): void): void> */
    private array $middlewares = [];

    private int $nextAckId = 0;

    public function __construct(public readonly SocketIO $server, public readonly string $name)
    {
        $this->adapter = $server->createAdapter($this);
    }

    /**
     * Adds a middleware. It lets the socket in with $next(), or refuses it with $next($error); it may call
     * $next later. A ConnectionError sends its data to the client too. An exception it throws refuses the socket.
     *
     * @param Closure(Socket, Closure(?Throwable=): void): void $middleware
     */
    public function use(Closure $middleware): static
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    public function adapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): BroadcastOperator
    {
        return $this->operator()->to($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function in(string|array $rooms): BroadcastOperator
    {
        return $this->operator()->in($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function except(string|array $rooms): BroadcastOperator
    {
        return $this->operator()->except($rooms);
    }

    /**
     * Kept for API compatibility: the server doesn't compress, so this has no effect.
     */
    public function compress(bool $compress): BroadcastOperator
    {
        return $this->operator()->compress($compress);
    }

    /**
     * Lets emit() take an acknowledgement callback; see BroadcastOperator::timeout().
     */
    public function timeout(int $milliseconds): BroadcastOperator
    {
        return $this->operator()->timeout($milliseconds);
    }

    /**
     * Sends an event to every socket of the namespace, on every server the adapter reaches.
     */
    public function emit(string $event, mixed ...$args): static
    {
        $this->operator()->emit($event, ...$args);

        return $this;
    }

    /**
     * Sends a `message` event to every socket of the namespace.
     */
    public function send(mixed ...$args): static
    {
        return $this->emit('message', ...$args);
    }

    /**
     * The ids of the namespace's sockets.
     *
     * @return list<string>
     */
    public function allSockets(): array
    {
        return $this->operator()->allSockets();
    }

    /**
     * The namespace's sockets; see BroadcastOperator::fetchSockets() for the callback, which reaches every server.
     *
     * @param (Closure(?Throwable, list<Socket|RemoteSocket>): void)|null $callback
     * @return ($callback is null ? list<Socket> : null)
     */
    public function fetchSockets(?Closure $callback = null): ?array
    {
        return $this->operator()->fetchSockets($callback);
    }

    /**
     * Makes every socket of the namespace join the rooms; use to() first to pick some.
     *
     * @param string|list<string> $rooms
     */
    public function socketsJoin(string|array $rooms): void
    {
        $this->operator()->socketsJoin($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsLeave(string|array $rooms): void
    {
        $this->operator()->socketsLeave($rooms);
    }

    public function disconnectSockets(bool $close = false): void
    {
        $this->operator()->disconnectSockets($close);
    }

    /**
     * Sends an event to this namespace on the other servers, whose listeners get it with on($event). A Closure as
     * the last argument gets one response per other server, as (?Throwable $error, array $responses): the error is
     * a TimeoutException when some didn't answer within 5 seconds. The data follows emit()'s rules.
     *
     * @throws InvalidArgumentException for a name the namespace keeps for itself, or data that can't be sent
     * @throws LogicException with an adapter that reaches no other server
     */
    public function serverSideEmit(string $event, mixed ...$args): void
    {
        if (in_array($event, ['connect', 'connection', 'new_namespace'], true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }
        $this->adapter->serverSideEmit([$event, ...array_values($args)]);
    }

    public function __get(string $name): BroadcastOperator
    {
        return match ($name) {
            'volatile' => $this->operator()->volatile,
            'local' => $this->operator()->local,
            default => throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name)),
        };
    }

    /**
     * The connected sockets of this namespace, in this process.
     *
     * @return array<string, Socket>
     */
    public function sockets(): array
    {
        return $this->sockets;
    }

    /**
     * @internal
     */
    public function initAdapter(): void
    {
        $this->adapter = $this->server->createAdapter($this);
    }

    /**
     * Runs the middlewares for a new socket, then connects it.
     *
     * @internal
     * @param array<mixed> $auth
     * @param Closure(Socket): void $onConnect
     */
    public function add(Client $client, array $auth, Closure $onConnect): void
    {
        $socket = new Socket($this, $client, $auth, $this->server->newSocketId());
        $this->connecting[$socket->id] = $socket;

        $done = function (?Throwable $error) use ($client, $socket, $onConnect): void {
            // The connection may have closed while a middleware was working.
            if (! $client->isOpen()) {
                $socket->cleanup();

                return;
            }
            if ($error !== null) {
                $socket->cleanup();
                $socket->refuse($error);

                return;
            }

            unset($this->connecting[$socket->id]);
            $this->sockets[$socket->id] = $socket;
            $socket->onConnect();
            $onConnect($socket);
            $this->dispatch('connect', $socket);
            $this->dispatch('connection', $socket);
        };
        $this->run($this->middlewares, 0, $socket, $done);
    }

    /**
     * @internal
     */
    public function remove(Socket $socket): void
    {
        unset($this->sockets[$socket->id], $this->connecting[$socket->id]);
    }

    /**
     * Tells the main namespace's `new_namespace` listeners about a namespace just created.
     *
     * @internal
     */
    public function announce(Nsp $nsp): void
    {
        $this->dispatch('new_namespace', $nsp);
    }

    /**
     * An event another server sent with serverSideEmit(), as its name and arguments.
     *
     * @internal
     * @param list<mixed> $args
     */
    public function receiveServerSideEmit(array $args): void
    {
        $event = array_shift($args);
        if (is_string($event)) {
            $this->dispatch($event, ...$args);
        }
    }

    /**
     * The next acknowledgement id; ids are counted per namespace, as in the reference.
     *
     * @internal
     */
    public function nextAckId(): int
    {
        return $this->nextAckId++;
    }

    private function operator(): BroadcastOperator
    {
        return new BroadcastOperator($this);
    }

    /**
     * @param list<Closure(Socket, Closure(?Throwable=): void): void> $middlewares
     * @param Closure(?Throwable): void $done
     */
    private function run(array $middlewares, int $index, Socket $socket, Closure $done): void
    {
        $middleware = $middlewares[$index] ?? null;
        if ($middleware === null) {
            $done(null);

            return;
        }

        $called = false;
        $next = function (?Throwable $error = null) use (&$called, $middlewares, $index, $socket, $done): void {
            if ($called) {
                return;
            }
            $called = true;
            if ($error !== null) {
                $done($error);

                return;
            }
            $this->run($middlewares, $index + 1, $socket, $done);
        };

        try {
            $middleware($socket, $next);
        } catch (Throwable $e) {
            // After next(), the exception comes from later code, such as a connection listener.
            if ($called) {
                throw $e;
            }
            $next($e);
        }
    }
}
