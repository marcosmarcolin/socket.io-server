<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\BroadcastOptions;
use Marcolin\SocketIO\Adapter\Cluster\Channels;
use Marcolin\SocketIO\Adapter\Cluster\MessageType;
use Marcolin\SocketIO\Adapter\Cluster\Wire;
use Marcolin\SocketIO\Adapter\Publisher;
use Marcolin\SocketIO\Adapter\Redis\BlockingRedisPublisher;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Parser\Encoder;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;

/**
 * Sends events to the clients of the servers, from any PHP code that isn't one of them, such as a PHP-FPM
 * request: it publishes what a server's PubSubAdapter would, and every server delivers it to its own sockets. As
 * the reference's emitter packages, it only sends: no acknowledgements, no fetchSockets.
 *
 * Immutable: of(), to(), in(), except() and volatile return a new emitter.
 *
 * @property-read Emitter $volatile the same selection, skipping sockets whose transport is busy
 */
final class Emitter
{
    /** The uid of an emitter's messages, which servers don't count as a server, as in the reference. */
    public const UID = 'emitter';

    // Names the namespace keeps for itself, refused by serverSideEmit() as in the reference.
    private const RESERVED_SERVER_EVENTS = ['connect', 'connection', 'new_namespace'];

    private readonly Encoder $encoder;

    private string $nsp = '/';

    /** @var list<string> */
    private array $rooms = [];

    /** @var list<string> */
    private array $except = [];

    private bool $volatile = false;

    /**
     * @param string $key the servers' channel key: the `key` of RedisAdapter::factory()
     */
    public function __construct(private readonly Publisher $publisher, private readonly string $key = 'socket.io')
    {
        $this->encoder = new Encoder();
    }

    /**
     * An emitter that publishes to the Redis server of RedisAdapter, with blocking I/O: no event loop needed.
     *
     * @param string $url redis://[[user]:password@]host[:port], the one the servers use
     * @param float $timeout seconds to connect, and to wait for each reply
     * @throws InvalidArgumentException for an invalid URL
     */
    public static function redis(
        string $url = RedisUrl::DEFAULT,
        string $key = 'socket.io',
        float $timeout = 5.0,
    ): self {
        return new self(new BlockingRedisPublisher(RedisUrl::parse($url), $timeout), $key);
    }

    public function __get(string $name): self
    {
        if ($name !== 'volatile') {
            throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name));
        }
        $copy = clone $this;
        $copy->volatile = true;

        return $copy;
    }

    /**
     * The same emitter for another namespace, with no room selected.
     */
    public function of(string $nsp): self
    {
        if (! str_starts_with($nsp, '/')) {
            throw new InvalidArgumentException(sprintf('A namespace starts with "/", got "%s"', $nsp));
        }
        $copy = new self($this->publisher, $this->key);
        $copy->nsp = $nsp;

        return $copy;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): self
    {
        $copy = clone $this;
        $copy->rooms = self::merge($this->rooms, $rooms);

        return $copy;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function in(string|array $rooms): self
    {
        return $this->to($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function except(string|array $rooms): self
    {
        $copy = clone $this;
        $copy->except = self::merge($this->except, $rooms);

        return $copy;
    }

    /**
     * Sends an event to the selected sockets of every server.
     *
     * @throws InvalidArgumentException for a reserved name, an acknowledgement callback, or data that can't be sent
     */
    public function emit(string $event, mixed ...$args): static
    {
        if (in_array($event, Packet::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }
        $this->publish($this->channels()->forRooms($this->rooms), MessageType::Broadcast, [
            'opts' => Wire::options($this->options()),
            'parts' => $this->parts($event, array_values($args)),
        ]);

        return $this;
    }

    /**
     * Sends a `message` event to the selected sockets.
     */
    public function send(mixed ...$args): static
    {
        return $this->emit('message', ...$args);
    }

    /**
     * Makes the selected sockets of every server join the rooms.
     *
     * @param string|list<string> $rooms
     */
    public function socketsJoin(string|array $rooms): void
    {
        $this->publish($this->channels()->forRooms($this->rooms), MessageType::SocketsJoin, [
            'opts' => Wire::options($this->options()),
            'rooms' => is_string($rooms) ? [$rooms] : $rooms,
        ]);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsLeave(string|array $rooms): void
    {
        $this->publish($this->channels()->forRooms($this->rooms), MessageType::SocketsLeave, [
            'opts' => Wire::options($this->options()),
            'rooms' => is_string($rooms) ? [$rooms] : $rooms,
        ]);
    }

    /**
     * Disconnects the selected sockets of every server; with $close, closes their connections.
     */
    public function disconnectSockets(bool $close = false): void
    {
        $this->publish($this->channels()->forRooms($this->rooms), MessageType::DisconnectSockets, [
            'opts' => Wire::options($this->options()),
            'close' => $close,
        ]);
    }

    /**
     * Sends an event to the namespace on every server, whose on($event) listeners get it.
     *
     * @throws InvalidArgumentException for a name the namespace keeps for itself, an acknowledgement callback, or
     *     data that can't be sent
     */
    public function serverSideEmit(string $event, mixed ...$args): void
    {
        if (in_array($event, self::RESERVED_SERVER_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved event name', $event));
        }
        $this->publish($this->channels()->namespace, MessageType::ServerSideEmit, [
            'parts' => $this->parts($event, array_values($args)),
        ]);
    }

    /**
     * @param list<mixed> $args
     * @return list<array{string, string}>
     */
    private function parts(string $event, array $args): array
    {
        foreach ($args as $arg) {
            if ($arg instanceof Closure) {
                throw new InvalidArgumentException('An emitter can\'t get acknowledgements: it only sends');
            }
        }

        return Wire::parts($this->encoder->encode(new Packet(PacketType::Event, $this->nsp, [$event, ...$args])));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function publish(string $channel, MessageType $type, array $fields): void
    {
        $this->publisher->publish($channel, Wire::message(self::UID, $type, $fields));
    }

    private function channels(): Channels
    {
        return new Channels($this->key, $this->nsp);
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
