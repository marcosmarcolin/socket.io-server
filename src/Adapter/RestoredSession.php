<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Marcolin\SocketIO\Binary;

/**
 * A session found again, with the events its socket missed meanwhile, encoded for its namespace.
 */
final class RestoredSession
{
    /**
     * @param list<list<string|Binary>> $missedPackets in the order they were sent
     */
    public function __construct(
        public readonly Session $session,
        public readonly array $missedPackets,
    ) {
    }
}
