<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\BroadcastOptions;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;

/**
 * Selects sockets of a namespace by room and acts on them. Immutable: each modifier returns a new operator.
 *
 * It acts on this process only; reaching other processes is the adapter's job.
 *
 * @property-read BroadcastOperator $volatile the same selection, skipping sockets whose transport is busy
 */
final class BroadcastOperator
{
    /**
     * @param list<string> $rooms
     * @param list<string> $except
     */
    public function __construct(
        private readonly Adapter $adapter,
        private readonly array $rooms = [],
        private readonly array $except = [],
        private readonly bool $volatile = false,
    ) {
    }

    public function __get(string $name): self
    {
        if ($name !== 'volatile') {
            throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name));
        }

        return new self($this->adapter, $this->rooms, $this->except, true);
    }

    /**
     * Targets the sockets in these rooms too.
     *
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): self
    {
        return new self($this->adapter, self::merge($this->rooms, $rooms), $this->except, $this->volatile);
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
        return new self($this->adapter, $this->rooms, self::merge($this->except, $rooms), $this->volatile);
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
     * @throws InvalidArgumentException for a reserved event name, or data that can't be sent
     * @throws LogicException for an acknowledgement callback, which broadcasts don't support
     */
    public function emit(string $event, mixed ...$args): self
    {
        if (in_array($event, Packet::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }
        $args = array_values($args);
        if ($args !== [] && $args[array_key_last($args)] instanceof Closure) {
            throw new LogicException('Acknowledgements are not supported when broadcasting');
        }

        $this->adapter->broadcast(new Packet(PacketType::Event, '/', [$event, ...$args]), $this->options());

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
        return $this->adapter->sockets($this->rooms);
    }

    /**
     * @return list<Socket>
     */
    public function fetchSockets(): array
    {
        return $this->adapter->fetchSockets($this->options());
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsJoin(string|array $rooms): void
    {
        $this->adapter->addSockets($this->options(), is_string($rooms) ? [$rooms] : $rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsLeave(string|array $rooms): void
    {
        $this->adapter->delSockets($this->options(), is_string($rooms) ? [$rooms] : $rooms);
    }

    /**
     * Disconnects the selected sockets from the namespace; with $close, closes their connections.
     */
    public function disconnectSockets(bool $close = false): void
    {
        $this->adapter->disconnectSockets($this->options(), $close);
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
