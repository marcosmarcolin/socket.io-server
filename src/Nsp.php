<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Event\Emitter;
use Throwable;

/**
 * A namespace.
 *
 * Events: `connection` and `connect` (Socket), when a socket joined, after the middlewares let it in.
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
     * The next acknowledgement id; ids are counted per namespace, as in the reference.
     *
     * @internal
     */
    public function nextAckId(): int
    {
        return $this->nextAckId++;
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
