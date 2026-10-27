<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

/**
 * Appends messages to the Stream the servers read, without waiting for the entry id: what the Emitter needs, as
 * Publisher is for publish and subscribe.
 */
interface Appender
{
    /**
     * @param array<string, mixed> $message arrays of scalars and lists, never objects
     */
    public function append(string $nsp, array $message): void;
}
