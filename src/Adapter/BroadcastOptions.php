<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

/**
 * Which sockets a broadcast reaches, and how.
 */
final class BroadcastOptions
{
    /**
     * @param list<string> $rooms the sockets in any of these rooms; all sockets when empty
     * @param list<string> $except minus the sockets in any of these rooms
     * @param bool $volatile skip the sockets whose transport can't take the packet right now
     */
    public function __construct(
        public readonly array $rooms = [],
        public readonly array $except = [],
        public readonly bool $volatile = false,
    ) {
    }
}
