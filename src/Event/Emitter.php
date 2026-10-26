<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Event;

use Closure;

/**
 * Event listeners for the classes that extend it.
 *
 * Dispatching is protected: only the emitter itself decides when its events fire.
 *
 * Every connection holds several emitters, so registrations live in flat arrays keyed by registration id, in
 * registration order: an array per event, or per registration, costs hundreds of bytes each in PHP.
 */
abstract class Emitter
{
    /** @var array<int, string> the event of each registration */
    private array $events = [];

    /** @var array<int, Closure> the listener of each registration */
    private array $listeners = [];

    /** @var array<int, true> the registrations made with once() */
    private array $once = [];

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
        foreach ($this->events as $id => $registered) {
            if ($registered === $event && ($listener === null || $this->listeners[$id] === $listener)) {
                $this->remove($id);
            }
        }

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function listeners(string $event): array
    {
        $listeners = [];
        foreach ($this->events as $id => $registered) {
            if ($registered === $event) {
                $listeners[] = $this->listeners[$id];
            }
        }

        return $listeners;
    }

    /**
     * The events that have listeners, as the reference's EventEmitter::eventNames().
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_values(array_unique($this->events));
    }

    /**
     * The same as off() with a listener, under the reference's name.
     */
    public function removeListener(string $event, Closure $listener): static
    {
        return $this->off($event, $listener);
    }

    /**
     * Removes the event's listeners, or every listener without an event, under the reference's name.
     */
    public function removeAllListeners(?string $event = null): static
    {
        if ($event === null) {
            $this->events = [];
            $this->listeners = [];
            $this->once = [];

            return $this;
        }

        return $this->off($event);
    }

    /**
     * Calls the event's listeners in registration order. Exceptions from a listener propagate.
     *
     * @return bool whether the event had listeners
     */
    protected function dispatch(string $event, mixed ...$args): bool
    {
        // Most dispatches on the hot path (packetCreate, drain) have no listener: leave before copying anything.
        if (! in_array($event, $this->events, true)) {
            return false;
        }
        // As registered when the dispatch began, as Node's EventEmitter: listeners added meanwhile wait for the
        // next one, and ones removed meanwhile still run, except a once() listener a nested dispatch already ran.
        $events = $this->events;
        $listeners = $this->listeners;
        $once = $this->once;
        $dispatched = false;
        foreach ($events as $id => $registered) {
            if ($registered !== $event) {
                continue;
            }
            $dispatched = true;
            if (isset($once[$id])) {
                if (! isset($this->listeners[$id])) {
                    continue;
                }
                $this->remove($id);
            }
            $listeners[$id](...$args);
        }

        return $dispatched;
    }

    private function add(string $event, Closure $listener, bool $once): static
    {
        $id = $this->nextId++;
        $this->events[$id] = $event;
        $this->listeners[$id] = $listener;
        if ($once) {
            $this->once[$id] = true;
        }

        return $this;
    }

    private function remove(int $id): void
    {
        unset($this->events[$id], $this->listeners[$id], $this->once[$id]);
    }
}
