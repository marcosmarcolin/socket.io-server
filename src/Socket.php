<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Event\AnyListeners;
use Marcolin\SocketIO\Event\Emitter;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use ReflectionFunction;
use Throwable;

/**
 * A client connected to one namespace.
 *
 * on() listens to the client's events: listeners get the event arguments, plus an acknowledgement Closure last
 * when the client asked for one. onAny() gets every event, with its name first; onAnyOutgoing() gets every event
 * sent to the client, by emit() or a broadcast. Reserved events:
 * - `disconnecting` and `disconnect` (string $reason, ?Throwable $description);
 * - `error` (Throwable), for a packet that broke the protocol, just before the connection closes, or for an event a
 *   use() middleware stopped.
 *
 * @property-read SocketEmitter $volatile emits that are dropped when the transport can't take them right now
 * @property-read BroadcastOperator $broadcast every other socket of the namespace
 * @property-read BroadcastOperator $local every other socket of the namespace, in this process only
 * @property-read bool $connected
 * @property-read bool $disconnected
 * @property-read list<string> $rooms
 */
final class Socket extends Emitter
{
    /**
     * @var array{
     *     headers: array<string, string>,
     *     time: string,
     *     address: string,
     *     xdomain: bool,
     *     secure: bool,
     *     issued: int,
     *     url: string,
     *     query: array<string, string>,
     *     auth: array<mixed>
     * } the request that opened the connection, and the CONNECT payload as auth
     */
    public readonly array $handshake;

    /** @var array<mixed> free-form user data */
    public array $data = [];

    public readonly EngineSocket $conn;

    public readonly Request $request;

    private bool $isConnected = false;

    // Once the socket left, join() does nothing.
    private bool $cleanedUp = false;

    /** @var array<int, Closure> acknowledgement callbacks by packet id */
    private array $acks = [];

    private readonly AnyListeners $anyListeners;

    private readonly AnyListeners $anyOutgoingListeners;

    /** @var list<Closure> the use() middlewares, which get each received event */
    private array $middlewares = [];

    /**
     * @internal created by Nsp
     * @param array<mixed> $auth
     */
    public function __construct(
        public readonly Nsp $nsp,
        public readonly Client $client,
        array $auth,
        public readonly string $id,
    ) {
        $this->anyListeners = new AnyListeners();
        $this->anyOutgoingListeners = new AnyListeners();
        $this->conn = $client->conn;
        $this->request = $client->conn->request;
        $this->handshake = [
            'headers' => $this->request->headers(),
            'time' => date('D M d Y H:i:s \G\M\TO'),
            'address' => $this->request->remoteAddress,
            'xdomain' => $this->request->header('Origin') !== null,
            'secure' => $this->request->secure,
            'issued' => (int) floor(microtime(true) * 1000),
            'url' => $this->request->uri,
            'query' => $this->request->queryParams(),
            'auth' => $auth,
        ];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'volatile' => new SocketEmitter($this, volatile: true),
            'broadcast' => $this->broadcastOperator(),
            'local' => $this->broadcastOperator()->local,
            'connected' => $this->isConnected,
            'disconnected' => ! $this->isConnected,
            'rooms' => $this->nsp->adapter()->socketRooms($this->id),
            default => throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name)),
        };
    }

    /**
     * Sends an event to the client. A Closure as the last argument asks for an acknowledgement and receives it.
     *
     * @throws InvalidArgumentException for a reserved event name, or data that can't be sent
     */
    public function emit(string $event, mixed ...$args): static
    {
        $this->emitWith($event, array_values($args), null, false);

        return $this;
    }

    /**
     * Sends a `message` event.
     */
    public function send(mixed ...$args): static
    {
        return $this->emit('message', ...$args);
    }

    /**
     * Listens to every event from the client, before its own listeners: the listener gets the event name, then
     * its arguments, with the acknowledgement Closure last when the client asked for one.
     */
    public function onAny(Closure $listener): static
    {
        $this->anyListeners->add($listener);

        return $this;
    }

    /**
     * Like onAny(), but runs before the listeners added so far.
     */
    public function prependAny(Closure $listener): static
    {
        $this->anyListeners->prepend($listener);

        return $this;
    }

    /**
     * Removes a listener added with onAny() or prependAny(), or all of them without one.
     */
    public function offAny(?Closure $listener = null): static
    {
        $this->anyListeners->remove($listener);

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function listenersAny(): array
    {
        return $this->anyListeners->all();
    }

    /**
     * Listens to every event sent to the client, by emit() or a broadcast, before it is written: the listener
     * gets the event name, then its arguments, without the acknowledgement callback.
     */
    public function onAnyOutgoing(Closure $listener): static
    {
        $this->anyOutgoingListeners->add($listener);

        return $this;
    }

    /**
     * Like onAnyOutgoing(), but runs before the listeners added so far.
     */
    public function prependAnyOutgoing(Closure $listener): static
    {
        $this->anyOutgoingListeners->prepend($listener);

        return $this;
    }

    /**
     * Removes a listener added with onAnyOutgoing() or prependAnyOutgoing(), or all of them without one.
     */
    public function offAnyOutgoing(?Closure $listener = null): static
    {
        $this->anyOutgoingListeners->remove($listener);

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function listenersAnyOutgoing(): array
    {
        return $this->anyOutgoingListeners->all();
    }

    /**
     * Emits whose acknowledgement callback gets a TimeoutException as first argument if the answer takes longer
     * than this, or null and the answer otherwise.
     */
    public function timeout(int $milliseconds): SocketEmitter
    {
        return (new SocketEmitter($this))->timeout($milliseconds);
    }

    /**
     * Kept for API compatibility: the server doesn't compress, so this has no effect.
     */
    public function compress(bool $compress): SocketEmitter
    {
        return (new SocketEmitter($this))->compress($compress);
    }

    /**
     * Targets the sockets in these rooms, except this one.
     *
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): BroadcastOperator
    {
        return $this->broadcastOperator()->to($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function in(string|array $rooms): BroadcastOperator
    {
        return $this->to($rooms);
    }

    /**
     * Targets every other socket of the namespace, minus the sockets in these rooms.
     *
     * @param string|list<string> $rooms
     */
    public function except(string|array $rooms): BroadcastOperator
    {
        return $this->broadcastOperator()->except($rooms);
    }

    /**
     * Adds a middleware for the events this socket receives, run in order after the onAny() listeners and before
     * the event's own listeners, as in the reference.
     *
     * It gets the event as [name, ...args], with the acknowledgement Closure last when the client asked for one,
     * and a $next Closure: $next() lets the event through, $next($error) stops it and emits `error` with $error.
     * It may call $next later; only the first call counts. An exception it throws before $next stops the event the
     * same way. Taking the event by reference (array &$event) lets it change what the next middlewares and the
     * listeners get.
     *
     * @param Closure(array<int, mixed>, Closure(?Throwable=): void): void $middleware
     */
    public function use(Closure $middleware): static
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function join(string|array $rooms): static
    {
        if (! $this->cleanedUp) {
            $this->nsp->adapter()->addAll($this->id, is_string($rooms) ? [$rooms] : $rooms);
        }

        return $this;
    }

    public function leave(string $room): static
    {
        $this->nsp->adapter()->del($this->id, $room);

        return $this;
    }

    /**
     * Leaves the namespace; with $close, closes the whole connection, leaving every namespace.
     */
    public function disconnect(bool $close = false): static
    {
        if (! $this->isConnected) {
            return $this;
        }
        if ($close) {
            $this->client->disconnect();

            return $this;
        }
        $this->packet(new Packet(PacketType::Disconnect));
        $this->onClose(DisconnectReason::ServerNamespaceDisconnect);

        return $this;
    }

    /**
     * @internal used by SocketEmitter
     * @param list<mixed> $args
     */
    public function emitWith(string $event, array $args, ?int $timeout, bool $volatile): void
    {
        if (in_array($event, Packet::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }

        $id = null;
        $last = $args === [] ? null : $args[array_key_last($args)];
        if ($last instanceof Closure) {
            array_pop($args);
            $id = $this->nsp->nextAckId();
            $this->registerAck($id, $last, $timeout);
        }

        $this->anyOutgoingListeners->notify($event, ...$args);
        $this->packet(new Packet(PacketType::Event, $this->nsp->name, [$event, ...$args], $id), $volatile);
    }

    /**
     * Waits for the client's answer to a packet sent with this id, as a broadcast with acknowledgement does.
     *
     * @internal used by the adapters
     */
    public function expectAck(int $id, Closure $ack): void
    {
        $this->acks[$id] = $ack;
    }

    /**
     * Whether a broadcast needs the event's data to notify this socket's onAnyOutgoing() listeners.
     *
     * @internal used by the adapters
     */
    public function hasOutgoingListeners(): bool
    {
        return ! $this->anyOutgoingListeners->isEmpty();
    }

    /**
     * Tells the onAnyOutgoing() listeners about an event about to be sent.
     *
     * @internal used by the adapters
     * @param list<mixed> $data the event name, then its arguments
     */
    public function notifyOutgoing(array $data): void
    {
        $event = array_shift($data);
        if (is_string($event)) {
            $this->anyOutgoingListeners->notify($event, ...$data);
        }
    }

    /**
     * Stops waiting for the answer to a packet, once a broadcast with acknowledgement timed out.
     *
     * @internal
     */
    public function forgetAck(int $id): void
    {
        unset($this->acks[$id]);
    }

    /**
     * Joins the socket's own room and confirms the connection to the client.
     *
     * @internal
     */
    public function onConnect(): void
    {
        $this->isConnected = true;
        $this->join($this->id);
        $this->packet(new Packet(PacketType::Connect, $this->nsp->name, ['sid' => $this->id]));
    }

    /**
     * @internal
     */
    public function onPacket(Packet $packet): void
    {
        match ($packet->type) {
            PacketType::Event => $this->onEvent($packet),
            PacketType::Ack => $this->onAck($packet),
            PacketType::Disconnect => $this->onClose(DisconnectReason::ClientNamespaceDisconnect),
            default => null,
        };
    }

    /**
     * @internal
     */
    public function onError(Throwable $error): void
    {
        $this->dispatch('error', $error);
    }

    /**
     * @internal
     */
    public function onClose(DisconnectReason $reason, ?Throwable $description = null): void
    {
        if (! $this->isConnected) {
            return;
        }
        $this->dispatch('disconnecting', $reason->value, $description);
        $this->cleanup();
        $this->client->remove($this);
        $this->isConnected = false;
        $this->dispatch('disconnect', $reason->value, $description);
    }

    /**
     * Leaves all rooms and the namespace.
     *
     * @internal
     */
    public function cleanup(): void
    {
        $this->nsp->adapter()->delAll($this->id);
        $this->nsp->remove($this);
        $this->cleanedUp = true;
    }

    /**
     * Answers a refused CONNECT.
     *
     * @internal
     */
    public function refuse(Throwable $error): void
    {
        $data = ['message' => $error->getMessage()];
        if ($error instanceof ConnectionError && $error->data !== null) {
            $data['data'] = $error->data;
        }
        $this->packet(new Packet(PacketType::ConnectError, $this->nsp->name, $data));
    }

    private function broadcastOperator(): BroadcastOperator
    {
        return new BroadcastOperator($this->nsp, [], [$this->id]);
    }

    private function packet(Packet $packet, bool $volatile = false): void
    {
        $this->client->packet(
            new Packet($packet->type, $this->nsp->name, $packet->data, $packet->id),
            $volatile,
        );
    }

    private function registerAck(int $id, Closure $ack, ?int $timeout): void
    {
        if ($timeout === null) {
            $this->acks[$id] = $ack;

            return;
        }

        $scheduler = $this->nsp->server->scheduler();
        $timer = $scheduler->after($timeout, function () use ($id, $ack): void {
            unset($this->acks[$id]);
            // Fills the answer parameters with null, so function (?Throwable $err, $answer) works.
            $required = (new ReflectionFunction($ack))->getNumberOfRequiredParameters();
            $ack(new TimeoutException('operation has timed out'), ...array_fill(0, max(0, $required - 1), null));
        });
        $this->acks[$id] = static function (mixed ...$args) use ($scheduler, $timer, $ack): void {
            $scheduler->cancel($timer);
            $ack(null, ...$args);
        };
    }

    private function onEvent(Packet $packet): void
    {
        $args = is_array($packet->data) ? array_values($packet->data) : [];
        $event = array_shift($args);
        if ($packet->id !== null) {
            $args[] = $this->ack($packet->id);
        }
        if ($this->isConnected && (is_string($event) || is_int($event) || is_float($event))) {
            $this->anyListeners->notify((string) $event, ...$args);
            // An onAny() listener may have disconnected the socket; the reference checks again too.
            if ($this->stillConnected()) {
                $this->runMiddlewares($this->middlewares, 0, [(string) $event, ...$args]);
            }
        }
    }

    /**
     * Runs the use() middlewares from $index on, then the event's listeners, unless one stopped it.
     *
     * @param list<Closure> $middlewares the middlewares when the event arrived
     * @param array<int, mixed> $event
     */
    private function runMiddlewares(array $middlewares, int $index, array $event): void
    {
        $middleware = $middlewares[$index] ?? null;
        if ($middleware === null) {
            $name = array_shift($event);
            // A middleware may have disconnected the socket, or renamed the event to something unusable.
            if ($this->stillConnected() && (is_string($name) || is_int($name))) {
                $this->dispatch((string) $name, ...array_values($event));
            }

            return;
        }

        $called = false;
        $next = function (?Throwable $error = null) use (&$called, &$event, $middlewares, $index): void {
            if ($called) {
                return;
            }
            $called = true;
            if ($error !== null) {
                $this->dispatch('error', $error);

                return;
            }
            $this->runMiddlewares($middlewares, $index + 1, $event);
        };

        try {
            $middleware($event, $next);
        } catch (Throwable $e) {
            // After next(), the exception comes from later code, such as an event listener.
            if ($called) {
                throw $e;
            }
            $next($e);
        }
    }

    /**
     * The Closure that answers an event; only its first call sends.
     */
    private function ack(int $id): Closure
    {
        $sent = false;

        return function (mixed ...$args) use ($id, &$sent): void {
            if ($sent) {
                return;
            }
            $sent = true;
            $this->packet(new Packet(PacketType::Ack, $this->nsp->name, array_values($args), $id));
        };
    }

    /**
     * The connection state read anew, after listeners that may have changed it.
     */
    private function stillConnected(): bool
    {
        return $this->isConnected;
    }

    private function onAck(Packet $packet): void
    {
        $id = $packet->id;
        $ack = $id === null ? null : ($this->acks[$id] ?? null);
        if ($ack === null) {
            return;
        }
        unset($this->acks[$id]);
        $ack(...(is_array($packet->data) ? array_values($packet->data) : []));
    }
}
