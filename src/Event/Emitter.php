<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Event;

use Closure;

/**
 * Event listeners for the classes that extend it.
 *
 * Dispatching is protected: only the emitter itself decides when its events fire.
 */
abstract class Emitter
{
    /** @var array<string, array<int, array{listener: Closure, once: bool}>> */
    private array $listeners = [];

    private int $nextId = 0;

    public function on(string $event, Closure $listener): static
    {
        return $this->add($event, $listener, false);
    }

    /**
     * Adds a listener that is removed right before its first call.
     */
    public function once(string $event, Closure $listener): static
    {
        return $this->add($event, $listener, true);
    }

    /**
     * Removes every registration of the listener, or all listeners of the event when none is given.
     */
    public function off(string $event, ?Closure $listener = null): static
    {
        if ($listener === null) {
            unset($this->listeners[$event]);

            return $this;
        }
        foreach ($this->listeners[$event] ?? [] as $id => $entry) {
            if ($entry['listener'] === $listener) {
                $this->remove($event, $id);
            }
        }

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function listeners(string $event): array
    {
        return array_values(array_map(
            static fn (array $entry): Closure => $entry['listener'],
            $this->listeners[$event] ?? [],
        ));
    }

    /**
     * Calls the event's listeners in registration order. Exceptions from a listener propagate.
     *
     * @return bool whether the event had listeners
     */
    protected function dispatch(string $event, mixed ...$args): bool
    {
        $entries = $this->listeners[$event] ?? [];
        foreach ($entries as $id => $entry) {
            if ($entry['once']) {
                // A once listener may already be gone if a nested dispatch fired it.
                if (! isset($this->listeners[$event][$id])) {
                    continue;
                }
                $this->remove($event, $id);
            }
            ($entry['listener'])(...$args);
        }

        return $entries !== [];
    }

    private function add(string $event, Closure $listener, bool $once): static
    {
        $this->listeners[$event][$this->nextId++] = ['listener' => $listener, 'once' => $once];

        return $this;
    }

    private function remove(string $event, int $id): void
    {
        unset($this->listeners[$event][$id]);
        if (($this->listeners[$event] ?? null) === []) {
            unset($this->listeners[$event]);
        }
    }
}
