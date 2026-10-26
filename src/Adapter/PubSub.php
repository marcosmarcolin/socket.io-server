<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;

/**
 * Publish and subscribe between processes, for PubSubAdapter.
 *
 * Messages are arrays of scalars and nested arrays, never objects, so they cross any serialization safely.
 * A publisher receives its own messages too, when it is subscribed to the channel.
 */
interface PubSub extends Publisher
{
    /**
     * Sets the one listener of a channel, replacing any previous one.
     *
     * @param Closure(mixed): void $listener
     */
    public function subscribe(string $channel, Closure $listener): void;

    public function unsubscribe(string $channel): void;
}
