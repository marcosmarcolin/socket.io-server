<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

/**
 * Keeps the rooms of one namespace. Adapters are the extension point for running on several processes.
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
}
