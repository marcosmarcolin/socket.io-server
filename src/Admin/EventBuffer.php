<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Admin;

/**
 * Counts what happens on the Engine.IO sessions per second, for the Admin UI's charts, as the reference's
 * EventBuffer: connections, disconnections by reason, packets and bytes in and out.
 */
final class EventBuffer
{
    /** @var array<string, array{timestamp: int, type: string, subType?: string, count: int}> */
    private array $buffer = [];

    /**
     * @param int $now the current time in milliseconds since the epoch; counts are kept per whole second
     */
    public function push(int $now, string $type, ?string $subType = null, int $count = 1): void
    {
        $timestamp = intdiv($now, 1000) * 1000;
        $key = $timestamp . ';' . $type . ';' . ($subType ?? '');
        if (isset($this->buffer[$key])) {
            $this->buffer[$key]['count'] += $count;

            return;
        }
        $this->buffer[$key] = $subType === null
            ? ['timestamp' => $timestamp, 'type' => $type, 'count' => $count]
            : ['timestamp' => $timestamp, 'type' => $type, 'subType' => $subType, 'count' => $count];
    }

    /**
     * @return list<array{timestamp: int, type: string, subType?: string, count: int}>
     */
    public function takeAll(): array
    {
        $values = array_values($this->buffer);
        $this->buffer = [];

        return $values;
    }
}
