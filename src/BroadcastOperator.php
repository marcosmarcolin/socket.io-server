<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\BroadcastOptions;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use Throwable;

/**
 * Selects sockets of a namespace by room and acts on them. Immutable: each modifier returns a new operator.
 *
 * Broadcasts and the socket helpers go through the namespace's adapter, which may reach other processes.
 *
 * @property-read BroadcastOperator $volatile the same selection, skipping sockets whose transport is busy
 */
final class BroadcastOperator
{
    /**
     * @param list<string> $rooms
     * @param list<string> $except
     * @param int|null $timeout how long an acknowledged broadcast waits for the answers, in milliseconds
     */
    public function __construct(
        private readonly Nsp $nsp,
        private readonly array $rooms = [],
        private readonly array $except = [],
        private readonly bool $volatile = false,
        private readonly ?int $timeout = null,
    ) {
    }

    public function __get(string $name): self
    {
        if ($name !== 'volatile') {
            throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name));
        }

        return new self($this->nsp, $this->rooms, $this->except, true, $this->timeout);
    }

    /**
     * Targets the sockets in these rooms too.
     *
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): self
    {
        return new self(
            $this->nsp,
            self::merge($this->rooms, $rooms),
            $this->except,
            $this->volatile,
            $this->timeout,
        );
    }

    /**
     * @param string|list<string> $rooms
     */
    public function in(string|array $rooms): self
    {
        return $this->to($rooms);
    }

    /**
     * Leaves out the sockets in these rooms.
     *
     * @param string|list<string> $rooms
     */
    public function except(string|array $rooms): self
    {
        return new self(
            $this->nsp,
            $this->rooms,
            self::merge($this->except, $rooms),
            $this->volatile,
            $this->timeout,
        );
    }

    /**
     * Lets emit() take an acknowledgement callback, which gets the answers of every selected socket.
     *
     * The callback gets null and the answers once all arrived, or a TimeoutException and the answers so far after
     * this many milliseconds.
     */
    public function timeout(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('The timeout can\'t be negative');
        }

        return new self($this->nsp, $this->rooms, $this->except, $this->volatile, $milliseconds);
    }

    /**
     * Kept for API compatibility: the server doesn't compress, so this has no effect.
     */
    public function compress(bool $compress): self
    {
        return $this;
    }

    /**
     * Sends an event to every selected socket.
     *
     * After timeout(), a Closure as the last argument gets the answers: one per socket, the first argument of
     * each client's acknowledgement, in the order they arrive.
     *
     * @throws InvalidArgumentException for a reserved event name, or data that can't be sent
     * @throws LogicException for an acknowledgement callback without timeout()
     */
    public function emit(string $event, mixed ...$args): self
    {
        if (in_array($event, Packet::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }
        $args = array_values($args);
        $ack = $args === [] ? null : $args[array_key_last($args)];
        if (! $ack instanceof Closure) {
            $this->nsp->adapter()->broadcast(new Packet(PacketType::Event, '/', [$event, ...$args]), $this->options());

            return $this;
        }
        // Without a timeout, a socket that never answers would keep the callback waiting forever.
        if ($this->timeout === null) {
            throw new LogicException(
                'An acknowledgement callback needs timeout() when broadcasting, like $io->timeout(5000)->emit(...)',
            );
        }
        array_pop($args);
        $this->emitWithAck(
            new Packet(PacketType::Event, '/', [$event, ...$args], $this->nsp->nextAckId()),
            $ack,
            $this->timeout,
        );

        return $this;
    }

    /**
     * Sends a `message` event.
     */
    public function send(mixed ...$args): self
    {
        return $this->emit('message', ...$args);
    }

    /**
     * The ids of the sockets in the rooms, or of all sockets; except() doesn't apply, as in the reference.
     *
     * @return list<string>
     */
    public function allSockets(): array
    {
        return $this->nsp->adapter()->sockets($this->rooms);
    }

    /**
     * The selected sockets. Without a callback, those of this process, at once. With one, those of every server
     * too: the callback gets this process's Socket objects and a RemoteSocket for each socket elsewhere, or a
     * TimeoutException with the sockets so far when some server didn't answer within 5 seconds.
     *
     * @param (Closure(?Throwable, list<Socket|RemoteSocket>): void)|null $callback
     * @return ($callback is null ? list<Socket> : null)
     */
    public function fetchSockets(?Closure $callback = null): ?array
    {
        if ($callback === null) {
            return $this->nsp->adapter()->fetchSockets($this->options());
        }
        $this->nsp->adapter()->fetchClusterSockets($this->options(), $callback);

        return null;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsJoin(string|array $rooms): void
    {
        $this->nsp->adapter()->addSockets($this->options(), is_string($rooms) ? [$rooms] : $rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsLeave(string|array $rooms): void
    {
        $this->nsp->adapter()->delSockets($this->options(), is_string($rooms) ? [$rooms] : $rooms);
    }

    /**
     * Disconnects the selected sockets from the namespace; with $close, closes their connections.
     */
    public function disconnectSockets(bool $close = false): void
    {
        $this->nsp->adapter()->disconnectSockets($this->options(), $close);
    }

    private function emitWithAck(Packet $packet, Closure $ack, int $timeout): void
    {
        $id = (int) $packet->id;
        $collector = new AckCollector($ack, $this->nsp->server->scheduler(), $timeout, function () use ($id): void {
            foreach ($this->nsp->sockets() as $socket) {
                $socket->forgetAck($id);
            }
        });
        $this->nsp->adapter()->broadcastWithAck(
            $packet,
            $this->options(),
            $collector->expect(...),
            $collector->answer(...),
        );
    }

    private function options(): BroadcastOptions
    {
        return new BroadcastOptions($this->rooms, $this->except, $this->volatile);
    }

    /**
     * @param list<string> $current
     * @param string|list<string> $added
     * @return list<string>
     */
    private static function merge(array $current, string|array $added): array
    {
        return array_values(array_unique([...$current, ...(is_string($added) ? [$added] : $added)]));
    }
}
