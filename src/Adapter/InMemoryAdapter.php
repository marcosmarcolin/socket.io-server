<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Socket;

/**
 * Rooms of the current process. Not final: other adapters extend it to reach other processes.
 */
class InMemoryAdapter implements Adapter
{
    /** @var array<string, array<string, true>> socket ids by room */
    protected array $rooms = [];

    /** @var array<string, array<string, true>> rooms by socket id */
    protected array $sids = [];

    public function __construct(protected readonly Nsp $nsp)
    {
    }

    public function addAll(string $socketId, array $rooms): void
    {
        foreach ($rooms as $room) {
            $this->sids[$socketId][$room] = true;
            $created = ! isset($this->rooms[$room]);
            $this->rooms[$room][$socketId] = true;
            if ($created) {
                $this->roomCreated($room);
            }
        }
    }

    public function del(string $socketId, string $room): void
    {
        unset($this->sids[$socketId][$room]);
        if (! isset($this->rooms[$room][$socketId])) {
            return;
        }
        unset($this->rooms[$room][$socketId]);
        if ($this->rooms[$room] === []) {
            unset($this->rooms[$room]);
            $this->roomDeleted($room);
        }
    }

    public function delAll(string $socketId): void
    {
        foreach (array_keys($this->sids[$socketId] ?? []) as $room) {
            $this->del($socketId, (string) $room);
        }
        unset($this->sids[$socketId]);
    }

    public function socketRooms(string $socketId): array
    {
        return array_map('strval', array_keys($this->sids[$socketId] ?? []));
    }

    public function broadcast(Packet $packet, BroadcastOptions $options): void
    {
        $this->deliver($this->encode($packet), $options);
    }

    public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void {
        $id = $packet->id ?? throw new LogicException('A broadcast with an acknowledgement needs a packet id');
        $parts = $this->encode($packet);
        $count = 0;
        foreach ($this->select($options) as $socket) {
            // A volatile packet the transport drops still counts, so the wait ends with the timeout.
            ++$count;
            $socket->expectAck($id, $ack);
            $socket->client->write($parts, $options->volatile);
        }
        $clientCount($count);
    }

    public function sockets(array $rooms): array
    {
        return array_map(
            static fn (Socket $socket): string => $socket->id,
            $this->select(new BroadcastOptions($rooms)),
        );
    }

    public function fetchSockets(BroadcastOptions $options): array
    {
        return $this->select($options);
    }

    public function addSockets(BroadcastOptions $options, array $rooms): void
    {
        foreach ($this->select($options) as $socket) {
            $socket->join($rooms);
        }
    }

    public function delSockets(BroadcastOptions $options, array $rooms): void
    {
        foreach ($this->select($options) as $socket) {
            foreach ($rooms as $room) {
                $socket->leave($room);
            }
        }
    }

    public function disconnectSockets(BroadcastOptions $options, bool $close): void
    {
        foreach ($this->select($options) as $socket) {
            $socket->disconnect($close);
        }
    }

    public function close(): void
    {
    }

    /**
     * Called when the first socket of this process joins a room.
     */
    protected function roomCreated(string $room): void
    {
    }

    /**
     * Called when the last socket of this process leaves a room.
     */
    protected function roomDeleted(string $room): void
    {
    }

    /**
     * Encodes a packet for this namespace, once for all its recipients.
     *
     * @return list<string|Binary>
     */
    protected function encode(Packet $packet): array
    {
        return $this->nsp->server->encoder()->encode(
            new Packet($packet->type, $this->nsp->name, $packet->data, $packet->id),
        );
    }

    /**
     * Writes encoded parts to the sockets of this process that the options select.
     *
     * @param list<string|Binary> $parts
     */
    protected function deliver(array $parts, BroadcastOptions $options): void
    {
        foreach ($this->select($options) as $socket) {
            $socket->client->write($parts, $options->volatile);
        }
    }

    /**
     * The connected sockets of this process that the options select, each once, in room order.
     *
     * The list is built before anyone acts on it, so callers may change rooms or disconnect sockets.
     *
     * @return list<Socket>
     */
    protected function select(BroadcastOptions $options): array
    {
        $connected = $this->nsp->sockets();
        $excluded = [];
        foreach ($options->except as $room) {
            $excluded += $this->rooms[$room] ?? [];
        }

        if ($options->rooms === []) {
            $ids = array_keys($this->sids);
        } else {
            $ids = [];
            foreach ($options->rooms as $room) {
                $ids = [...$ids, ...array_keys($this->rooms[$room] ?? [])];
            }
        }

        $selected = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            // A socket in several rooms keeps its first place.
            if (! isset($excluded[$id]) && isset($connected[$id])) {
                $selected[$id] = $connected[$id];
            }
        }

        return array_values($selected);
    }
}
