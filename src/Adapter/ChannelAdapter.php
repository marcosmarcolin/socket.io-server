<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;

/**
 * Broadcasts across the processes of a server through publish and subscribe, by default workerman/channel.
 *
 * Each broadcast reaches the sockets of this process at once, then is published already encoded, so every other
 * process writes it to its own sockets without encoding it again. Only broadcasts cross processes; the socket
 * helpers (fetchSockets, socketsJoin...) act on this process, as in InMemoryAdapter.
 *
 * Channels, per namespace:
 * - "socket.io#<nsp>#", which every process of the namespace subscribes to;
 * - "socket.io#<nsp>#<room>#", which a process subscribes to while one of its sockets is in the room.
 *
 * A socket's own room (24 hex characters) gets no channel: it goes through the namespace channel.
 */
final class ChannelAdapter extends InMemoryAdapter
{
    private readonly string $prefix;

    // Tells this adapter's messages from the others', since a publisher receives its own.
    private readonly string $id;

    public function __construct(Nsp $nsp, private readonly PubSub $pubSub)
    {
        parent::__construct($nsp);
        $this->id = bin2hex(random_bytes(8));
        $this->prefix = 'socket.io#' . $nsp->name . '#';
        $this->listen($this->prefix);
    }

    /**
     * An adapter factory for SocketIO::adapter(), on a workerman/channel server.
     *
     * Call it in workerStart: it connects to the channel server, which needs the running event loop.
     *
     * @return Closure(Nsp): Adapter
     */
    public static function factory(string $host = '127.0.0.1', int $port = 2206): Closure
    {
        $pubSub = new WorkermanChannelPubSub($host, $port);

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub);
    }

    /**
     * Not supported across processes yet: the answers of other processes' sockets wouldn't come back.
     */
    public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void {
        throw new LogicException('ChannelAdapter doesn\'t support broadcasting with an acknowledgement yet');
    }

    public function broadcast(Packet $packet, BroadcastOptions $options): void
    {
        $parts = $this->encode($packet);
        $this->deliver(
            $parts,
            $options,
            static fn (): array => is_array($packet->data) ? array_values($packet->data) : [],
        );

        $this->pubSub->publish($this->channelFor($options->rooms), [
            'sender' => $this->id,
            'parts' => array_map(
                static fn (string|Binary $part): array => $part instanceof Binary
                    ? ['binary', $part->data]
                    : ['text', $part],
                $parts,
            ),
            'rooms' => $options->rooms,
            'except' => $options->except,
            'volatile' => $options->volatile,
        ]);
    }

    /**
     * Stops listening to the namespace's channels.
     */
    public function close(): void
    {
        foreach (array_keys($this->rooms) as $room) {
            $this->roomDeleted((string) $room);
        }
        $this->pubSub->unsubscribe($this->prefix);
    }

    protected function roomCreated(string $room): void
    {
        if (! self::isSocketRoom($room)) {
            $this->listen($this->prefix . $room . '#');
        }
    }

    protected function roomDeleted(string $room): void
    {
        if (! self::isSocketRoom($room)) {
            $this->pubSub->unsubscribe($this->prefix . $room . '#');
        }
    }

    /**
     * The one channel a broadcast goes to.
     *
     * A single room has its own channel, which only the processes with members of it subscribe to. Anything else
     * goes once to the namespace channel, and each process filters by the rooms itself: publishing to each room's
     * channel would reach a process twice when it has members in two of the rooms.
     *
     * @param list<string> $rooms
     */
    private function channelFor(array $rooms): string
    {
        if (count($rooms) === 1 && ! self::isSocketRoom($rooms[0])) {
            return $this->prefix . $rooms[0] . '#';
        }

        return $this->prefix;
    }

    private function listen(string $channel): void
    {
        $this->pubSub->subscribe($channel, function (mixed $message): void {
            $this->onMessage($message);
        });
    }

    /**
     * Delivers another process's broadcast to the sockets of this one. Malformed messages are ignored.
     */
    private function onMessage(mixed $message): void
    {
        if (! is_array($message) || ($message['sender'] ?? null) === $this->id) {
            return;
        }
        $parts = self::parts($message['parts'] ?? null);
        $rooms = self::strings($message['rooms'] ?? null);
        $except = self::strings($message['except'] ?? null);
        $volatile = $message['volatile'] ?? null;
        if ($parts === null || $rooms === null || $except === null || ! is_bool($volatile)) {
            return;
        }

        $this->deliver(
            $parts,
            new BroadcastOptions($rooms, $except, $volatile),
            static fn (): array => self::decode($parts),
        );
    }

    /**
     * The event name and arguments of another process's broadcast, for onAnyOutgoing() listeners.
     *
     * @param list<string|Binary> $parts
     * @return list<mixed> empty when the parts aren't one event
     */
    private static function decode(array $parts): array
    {
        $decoder = new Decoder();
        $packet = null;
        try {
            foreach ($parts as $part) {
                $packet = $decoder->add($part);
            }
        } catch (InvalidPacketException) {
            return [];
        }
        // The decoder gives binary events the plain Event type.
        return $packet?->type === PacketType::Event && is_array($packet->data) ? array_values($packet->data) : [];
    }

    /**
     * @return list<string|Binary>|null
     */
    private static function parts(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }
        $parts = [];
        foreach ($value as $part) {
            $kind = is_array($part) ? ($part[0] ?? null) : null;
            $data = is_array($part) ? ($part[1] ?? null) : null;
            if (! is_string($data) || ($kind !== 'text' && $kind !== 'binary')) {
                return null;
            }
            $parts[] = $kind === 'binary' ? new Binary($data) : $data;
        }

        return $parts;
    }

    /**
     * @return list<string>|null
     */
    private static function strings(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * A socket's own room: socket ids are 24 lowercase hex characters.
     */
    private static function isSocketRoom(string $room): bool
    {
        return preg_match('/^[0-9a-f]{24}$/', $room) === 1;
    }
}
