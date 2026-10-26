<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\Engine\Scheduler;

/**
 * A scheduler whose clock only moves when the test calls advance().
 */
final class FakeScheduler implements Scheduler
{
    /** @var array<int, array{due: int, callback: Closure(): void}> */
    private array $timers = [];

    private int $now = 0;

    private int $nextId = 1;

    public function after(int $delay, Closure $callback): int
    {
        if ($delay < 0) {
            throw new InvalidArgumentException('The delay must be zero or more');
        }
        $id = $this->nextId++;
        $this->timers[$id] = ['due' => $this->now + $delay, 'callback' => $callback];

        return $id;
    }

    public function cancel(int $id): void
    {
        unset($this->timers[$id]);
    }

    /**
     * Moves the clock forward, firing due timers in order, including ones scheduled while advancing.
     */
    public function advance(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('The clock cannot go back');
        }
        $target = $this->now + $milliseconds;
        while (($id = $this->nextDue($target)) !== null) {
            $timer = $this->timers[$id];
            unset($this->timers[$id]);
            // A timer that fires late, after elapse(), runs at the current time.
            $this->now = max($this->now, $timer['due']);
            ($timer['callback'])();
        }
        $this->now = $target;
    }

    public function now(): int
    {
        return $this->now;
    }

    /**
     * Moves the clock forward without firing the timers that become due, as an event loop that handles ready
     * sockets before due timers, such as Workerman's.
     */
    public function elapse(int $milliseconds): void
    {
        $this->now += $milliseconds;
    }

    public function pending(): int
    {
        return count($this->timers);
    }

    private function nextDue(int $target): ?int
    {
        $next = null;
        foreach ($this->timers as $id => $timer) {
            // Ids grow with creation, so ties keep the scheduling order.
            if ($timer['due'] <= $target && ($next === null || $timer['due'] < $this->timers[$next]['due'])) {
                $next = $id;
            }
        }

        return $next;
    }
}
