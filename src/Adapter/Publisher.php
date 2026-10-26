<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

/**
 * Publishes messages to the other servers: what the Emitter needs, and the publishing half of a PubSub.
 */
interface Publisher
{
    /**
     * @param array<string, mixed> $message arrays of scalars and lists, never objects
     */
    public function publish(string $channel, array $message): void;
}
