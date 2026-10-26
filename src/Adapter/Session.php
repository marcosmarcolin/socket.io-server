<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

/**
 * What a socket that left leaves behind for its recovery: its ids, rooms and data.
 */
final class Session
{
    /**
     * @param string $sid the socket's id, which it gets back
     * @param string $pid the private id the client sends to recover it
     * @param list<string> $rooms
     * @param array<mixed> $data
     */
    public function __construct(
        public readonly string $sid,
        public readonly string $pid,
        public readonly array $rooms,
        public readonly array $data,
    ) {
    }
}
