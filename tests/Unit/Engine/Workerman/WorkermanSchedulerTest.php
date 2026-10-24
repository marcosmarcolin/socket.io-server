<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Workerman;

use ArrayObject;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use PHPUnit\Framework\TestCase;
use Workerman\Events\Select;
use Workerman\Timer;

final class WorkermanSchedulerTest extends TestCase
{
    public function testRunsCallbacksAfterTheDelayUnlessCancelled(): void
    {
        $loop = new Select();
        Timer::init($loop);
        $scheduler = new WorkermanScheduler();
        $fired = new ArrayObject();

        $scheduler->after(10, function () use ($fired): void {
            $fired->append('kept');
        });
        $cancelled = $scheduler->after(10, function () use ($fired): void {
            $fired->append('cancelled');
        });
        $scheduler->cancel($cancelled);
        $loop->delay(0.1, static function () use ($loop): void {
            $loop->stop();
        });
        $started = microtime(true);
        $loop->run();

        self::assertSame(['kept'], $fired->getArrayCopy());
        self::assertGreaterThanOrEqual(0.01, microtime(true) - $started);
    }
}
