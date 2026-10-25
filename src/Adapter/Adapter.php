<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Socket;

/**
 * Keeps the rooms of one namespace and reaches its sockets. Adapters are the extension point for running on
 * several processes; the methods mirror the reference's adapter.
 */
interface Adapter
{
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
     * The ids of the sockets in any of these rooms, or of all sockets.
     *
     * @param list<string> $rooms
     * @return list<string>
     */
    public function sockets(array $rooms): array;

    /**
     * @return list<Socket>
     */
    public function fetchSockets(BroadcastOptions $options): array;

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
     * Releases what the adapter holds outside this process, when the server shuts down.
     */
    public function close(): void;
}
