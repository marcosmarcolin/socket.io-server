<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\TimeoutException;

/**
 * Keeps the rooms of one namespace and reaches its sockets. Adapters are the extension point for running on
 * several processes; the methods mirror the reference's adapter.
 *
 * Its events are `create-room`, `delete-room`, `join-room` and `leave-room`; see InMemoryAdapter.
 */
interface Adapter
{
    /**
     * Listens to the adapter's room events.
     */
    public function on(string $event, Closure $listener): static;

    public function off(string $event, ?Closure $listener = null): static;

    /**
     * @param list<string> $rooms
     */
    public function addAll(string $socketId, array $rooms): void;

    public function del(string $socketId, string $room): void;

    /**
     * Removes the socket from all its rooms.
     */
    public function delAll(string $socketId): void;

    /**
     * @return list<string>
     */
    public function socketRooms(string $socketId): array;

    /**
     * Sends a packet to the sockets the options select. The adapter sets the namespace and encodes it once.
     */
    public function broadcast(Packet $packet, BroadcastOptions $options): void;

    /**
     * Sends a packet with an acknowledgement id to the sockets the options select, and waits for their answers.
     *
     * @param Closure(int): void $clientCount gets how many sockets this process reached
     * @param Closure(mixed...): void $ack gets each socket's answer
     */
    public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void;

    /**
     * The ids of the sockets in any of these rooms, or of all sockets.
     *
     * @param list<string> $rooms
     * @return list<string>
     */
    public function sockets(array $rooms): array;

    /**
     * The selected sockets of this process.
     *
     * @return list<Socket>
     */
    public function fetchSockets(BroadcastOptions $options): array;

    /**
     * The selected sockets of every server: this process's, then the others' as they answer.
     *
     * @param Closure(?TimeoutException, list<Socket|RemoteSocket>): void $callback gets a TimeoutException, with
     *     the sockets so far, when some server didn't answer in time
     */
    public function fetchClusterSockets(BroadcastOptions $options, Closure $callback): void;

    /**
     * Makes the selected sockets join the rooms.
     *
     * @param list<string> $rooms
     */
    public function addSockets(BroadcastOptions $options, array $rooms): void;

    /**
     * Makes the selected sockets leave the rooms.
     *
     * @param list<string> $rooms
     */
    public function delSockets(BroadcastOptions $options, array $rooms): void;

    public function disconnectSockets(BroadcastOptions $options, bool $close): void;

    /**
     * How many servers run this namespace, this one included.
     */
    public function serverCount(): int;

    /**
     * Sends an event to the namespace on the other servers, which receive it with $nsp->on($event).
     *
     * @param list<mixed> $packet the event name and arguments; a Closure last gets one response per other server,
     *     as (?TimeoutException $error, list<mixed> $responses)
     */
    public function serverSideEmit(array $packet): void;

    /**
     * Releases what the adapter holds outside this process, when the server shuts down.
     */
    public function close(): void;
}
