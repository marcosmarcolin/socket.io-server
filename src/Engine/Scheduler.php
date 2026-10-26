<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;

/**
 * Runs callbacks after a delay. All timing goes through it, so tests can replace the clock.
 */
interface Scheduler
{
    /**
     * Runs the callback once, after the given delay.
     *
     * @param int $delay milliseconds, zero or more
     * @param Closure(): void $callback
     * @return int a timer id, to pass to cancel()
     */
    public function after(int $delay, Closure $callback): int;

    /**
     * Cancels a timer. Unknown, fired or already cancelled ids are ignored.
     */
    public function cancel(int $id): void;

    /**
     * The current time in milliseconds, from a clock that never goes back; only differences between two calls
     * mean anything.
     */
    public function now(): int;
}
