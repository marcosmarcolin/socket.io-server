<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Marcolin\SocketIO\Nsp;

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
            $this->rooms[$room][$socketId] = true;
        }
    }

    public function del(string $socketId, string $room): void
    {
        unset($this->sids[$socketId][$room], $this->rooms[$room][$socketId]);
        if (($this->rooms[$room] ?? null) === []) {
            unset($this->rooms[$room]);
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
}
