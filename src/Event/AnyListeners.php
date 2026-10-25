<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Event;

use Closure;

/**
 * Catch-all listeners, called with the event name and its arguments, as onAny() and onAnyOutgoing() keep them.
 *
 * @internal
 */
final class AnyListeners
{
    /** @var list<Closure> */
    private array $listeners = [];

    public function add(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function prepend(Closure $listener): void
    {
        array_unshift($this->listeners, $listener);
    }

    /**
     * Removes the first registration of the listener, or every listener without one.
     */
    public function remove(?Closure $listener): void
    {
        if ($listener === null) {
            $this->listeners = [];

            return;
        }
        $index = array_search($listener, $this->listeners, true);
        if ($index !== false) {
            array_splice($this->listeners, $index, 1);
        }
    }

    /**
     * @return list<Closure>
     */
    public function all(): array
    {
        return $this->listeners;
    }

    public function isEmpty(): bool
    {
        return $this->listeners === [];
    }

    /**
     * Calls every listener registered now; a listener added or removed meanwhile changes only later calls.
     */
    public function notify(string $event, mixed ...$args): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event, ...$args);
        }
    }
}
