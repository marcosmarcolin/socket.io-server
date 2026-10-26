<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;
use Workerman\Timer;

/**
 * Workerman timers. Only works inside a running worker, or after Timer::init() in tests.
 */
final class WorkermanScheduler implements Scheduler
{
    public function after(int $delay, Closure $callback): int
    {
        return Timer::add($delay / 1000, $callback, [], false);
    }

    public function cancel(int $id): void
    {
        Timer::del($id);
    }

    public function now(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
