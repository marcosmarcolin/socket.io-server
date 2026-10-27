<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Event\Emitter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use Marcolin\SocketIO\Socket;

/**
 * Rooms of the current process. Not final: other adapters extend it to reach other processes.
 *
 * Events, as the reference's adapter, for the sockets of this process: `create-room` (string $room) and
 * `delete-room` (string $room) when a room gets its first member or loses its last; `join-room` and `leave-room`
 * (string $room, string $id) when a socket joins or leaves one, after `create-room` and before `delete-room`.
 * A socket's own room counts too.
 *
 * With the connectionStateRecovery option, it also keeps, for maxDisconnectionDuration, the sessions of the sockets
 * that left and the events broadcast meanwhile, as the reference's SessionAwareAdapter: each event without an
 * acknowledgement that isn't volatile gets an offset as its last argument, which the client sends back to recover.
 */
class InMemoryAdapter extends Emitter implements Adapter
{
    /** @var array<string, array<string, true>> socket ids by room */
    protected array $rooms = [];

    /** @var array<string, array<string, true>> rooms by socket id */
    protected array $sids = [];

    /** @var array<string, array{session: Session, disconnectedAt: int}> by private id, oldest first */
    private array $sessions = [];

    /**
     * @var list<array{offset: string, emittedAt: int, parts: list<string|Binary>, rooms: list<string>,
     *     except: list<string>}> the events kept for recovery, oldest first
     */
    private array $packets = [];

    private ?string $offsetPrefix = null;

    private int $nextOffset = 0;

    public function __construct(protected readonly Nsp $nsp)
    {
    }

    public function addAll(string $socketId, array $rooms): void
    {
        foreach ($rooms as $room) {
            $this->sids[$socketId][$room] = true;
            $created = ! isset($this->rooms[$room]);
            $joined = ! isset($this->rooms[$room][$socketId]);
            $this->rooms[$room][$socketId] = true;
            if ($created) {
                $this->roomCreated($room);
                $this->dispatch('create-room', $room);
            }
            if ($joined) {
                $this->dispatch('join-room', $room, $socketId);
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
        $this->dispatch('leave-room', $room, $socketId);
        if ($this->rooms[$room] === []) {
            unset($this->rooms[$room]);
            $this->roomDeleted($room);
            $this->dispatch('delete-room', $room);
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
        $offset = $this->recoverable($packet, $options) ? $this->offset() : null;
        if ($offset !== null) {
            $packet = self::withOffset($packet, $offset);
        }
        $parts = $this->encode($packet);
        if ($offset !== null) {
            $this->keep($offset, $parts, $options);
        }
        $this->deliver($parts, $options, self::eventData($packet));
    }

    public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void {
        $id = $packet->id ?? throw new LogicException('A broadcast with an acknowledgement needs a packet id');
        $data = self::eventData($packet);
        $parts = $this->encode($packet);
        $count = 0;
        foreach ($this->select($options) as $socket) {
            // A volatile packet the transport drops still counts, so the wait ends with the timeout.
            ++$count;
            $socket->expectAck($id, $ack);
            if ($socket->hasOutgoingListeners()) {
                $socket->notifyOutgoing($data());
            }
            $socket->client->write($parts, $options->volatile, $options->compress);
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

    public function fetchClusterSockets(BroadcastOptions $options, Closure $callback): void
    {
        $callback(null, $this->fetchSockets($options));
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

    public function serverCount(): int
    {
        return 1;
    }

    /**
     * @throws LogicException always: there are no other servers to reach
     */
    public function serverSideEmit(array $packet): void
    {
        throw new LogicException(
            'serverSideEmit() needs an adapter that reaches other servers, such as ChannelAdapter or RedisAdapter',
        );
    }

    public function persistSession(Session $session): void
    {
        $recovery = $this->nsp->server->connectionStateRecovery;
        if ($recovery === null) {
            return;
        }
        $now = $this->nsp->server->scheduler()->now();
        foreach ($this->sessions as $pid => $kept) {
            if ($kept['disconnectedAt'] + $recovery->maxDisconnectionDuration >= $now) {
                break;
            }
            unset($this->sessions[$pid]);
        }
        // Removed first, so the newest stays last.
        unset($this->sessions[$session->pid]);
        $this->sessions[$session->pid] = ['session' => $session, 'disconnectedAt' => $now];
    }

    public function restoreSession(string $pid, string $offset, Closure $callback): void
    {
        $callback($this->restore($pid, $offset));
    }

    private function restore(string $pid, string $offset): ?RestoredSession
    {
        $recovery = $this->nsp->server->connectionStateRecovery;
        $kept = $this->sessions[$pid] ?? null;
        if ($recovery === null || $kept === null) {
            return null;
        }
        $now = $this->nsp->server->scheduler()->now();
        if ($kept['disconnectedAt'] + $recovery->maxDisconnectionDuration < $now) {
            unset($this->sessions[$pid]);

            return null;
        }
        $found = false;
        $missed = [];
        foreach ($this->packets as $packet) {
            if ($found && self::reaches($kept['session']->rooms, $packet['rooms'], $packet['except'])) {
                $missed[] = $packet['parts'];
            }
            $found = $found || $packet['offset'] === $offset;
        }
        if (! $found) {
            // The offset may be too old.
            return null;
        }
        // Unlike the reference, a session is recovered once: two clients can't take the same socket id.
        unset($this->sessions[$pid]);

        return new RestoredSession($kept['session'], $missed);
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
     * @param Closure(): list<mixed> $data the event name and arguments, for the sockets' onAnyOutgoing()
     *        listeners; called only when one of them has such a listener
     */
    protected function deliver(array $parts, BroadcastOptions $options, Closure $data): void
    {
        $event = null;
        foreach ($this->select($options) as $socket) {
            if ($socket->hasOutgoingListeners()) {
                $event ??= $data();
                $socket->notifyOutgoing($event);
            }
            $socket->client->write($parts, $options->volatile, $options->compress);
        }
    }

    /**
     * Whether connection state recovery keeps this broadcast: as the reference, an event without an
     * acknowledgement, which can't be restored, that isn't volatile, which may be lost anyway.
     */
    protected function recoverable(Packet $packet, BroadcastOptions $options): bool
    {
        return $this->nsp->server->connectionStateRecovery !== null
            && $packet->type === PacketType::Event
            && $packet->id === null
            && ! $options->volatile
            && is_array($packet->data);
    }

    /**
     * The packet with the offset as its last argument, which the client sends back to recover.
     */
    protected static function withOffset(Packet $packet, string $offset): Packet
    {
        $data = is_array($packet->data) ? array_values($packet->data) : [];

        return new Packet($packet->type, $packet->nsp, [...$data, $offset], $packet->id);
    }

    /**
     * A new offset, unique across adapters: a random prefix, so neither another process's nor a client's from
     * before a restart matches.
     */
    protected function offset(): string
    {
        $this->offsetPrefix ??= bin2hex(random_bytes(6));

        return $this->offsetPrefix . '.' . base_convert((string) $this->nextOffset++, 10, 36);
    }

    /**
     * Keeps an event for recovery, dropping the ones older than maxDisconnectionDuration.
     *
     * @param list<string|Binary> $parts
     */
    protected function keep(string $offset, array $parts, BroadcastOptions $options): void
    {
        $recovery = $this->nsp->server->connectionStateRecovery;
        if ($recovery === null) {
            return;
        }
        $now = $this->nsp->server->scheduler()->now();
        $expired = 0;
        while (
            isset($this->packets[$expired])
            && $this->packets[$expired]['emittedAt'] + $recovery->maxDisconnectionDuration < $now
        ) {
            ++$expired;
        }
        if ($expired > 0) {
            $this->packets = array_slice($this->packets, $expired);
        }
        $this->packets[] = [
            'offset' => $offset,
            'emittedAt' => $now,
            'parts' => $parts,
            'rooms' => $options->rooms,
            'except' => $options->except,
        ];
    }

    /**
     * Whether a kept event reaches a socket in these rooms, as the reference's shouldIncludePacket().
     *
     * @param list<string> $socketRooms
     * @param list<string> $rooms
     * @param list<string> $except
     */
    protected static function reaches(array $socketRooms, array $rooms, array $except): bool
    {
        return ($rooms === [] || array_intersect($socketRooms, $rooms) !== [])
            && array_intersect($socketRooms, $except) === [];
    }

    /**
     * @return Closure(): list<mixed>
     */
    protected static function eventData(Packet $packet): Closure
    {
        return static fn (): array => is_array($packet->data) ? array_values($packet->data) : [];
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
