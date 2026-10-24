<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Fake;

use ArrayObject;
use InvalidArgumentException;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\TestCase;

final class FakeSchedulerTest extends TestCase
{
    public function testFiresOnlyWhenTheDelayHasPassed(): void
    {
        $scheduler = new FakeScheduler();
        $fired = new ArrayObject();
        $scheduler->after(100, function () use ($fired): void {
            $fired->append(true);
        });

        $scheduler->advance(99);
        self::assertCount(0, $fired);

        $scheduler->advance(1);
        self::assertCount(1, $fired);

        $scheduler->advance(1000);
        self::assertCount(1, $fired);
        self::assertSame(0, $scheduler->pending());
    }

    public function testFiresInDueOrderThenSchedulingOrder(): void
    {
        $scheduler = new FakeScheduler();
        $order = [];
        $scheduler->after(200, function () use (&$order): void {
            $order[] = 'c';
        });
        $scheduler->after(100, function () use (&$order): void {
            $order[] = 'a';
        });
        $scheduler->after(100, function () use (&$order): void {
            $order[] = 'b';
        });

        $scheduler->advance(500);

        self::assertSame(['a', 'b', 'c'], $order);
    }

    public function testCallbacksSeeTheirOwnDueTime(): void
    {
        $scheduler = new FakeScheduler();
        $seen = null;
        $scheduler->after(30, function () use ($scheduler, &$seen): void {
            $seen = $scheduler->now();
        });

        $scheduler->advance(100);

        self::assertSame(30, $seen);
        self::assertSame(100, $scheduler->now());
    }

    public function testFiresTimersScheduledWhileAdvancing(): void
    {
        $scheduler = new FakeScheduler();
        $times = [];
        $tick = function () use ($scheduler, &$times, &$tick): void {
            $times[] = $scheduler->now();
            $scheduler->after(100, $tick);
        };
        $scheduler->after(100, $tick);

        $scheduler->advance(350);

        self::assertSame([100, 200, 300], $times);
        self::assertSame(1, $scheduler->pending());
    }

    public function testCancelledTimersDoNotFire(): void
    {
        $scheduler = new FakeScheduler();
        $fired = false;
        $id = $scheduler->after(10, function () use (&$fired): void {
            $fired = true;
        });

        $scheduler->cancel($id);
        $scheduler->cancel($id);
        $scheduler->cancel(999);
        $scheduler->advance(10);

        self::assertFalse($fired);
    }

    public function testATimerCanCancelALaterOne(): void
    {
        $scheduler = new FakeScheduler();
        $fired = false;
        $later = $scheduler->after(20, function () use (&$fired): void {
            $fired = true;
        });
        $scheduler->after(10, function () use ($scheduler, $later): void {
            $scheduler->cancel($later);
        });

        $scheduler->advance(20);

        self::assertFalse($fired);
    }

    public function testZeroDelayFiresOnTheNextAdvance(): void
    {
        $scheduler = new FakeScheduler();
        $fired = new ArrayObject();
        $scheduler->after(0, function () use ($fired): void {
            $fired->append(true);
        });

        self::assertCount(0, $fired);
        $scheduler->advance(0);
        self::assertCount(1, $fired);
    }

    public function testRejectsNegativeDelays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FakeScheduler())->after(-1, static function (): void {
        });
    }

    public function testRejectsGoingBackInTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FakeScheduler())->advance(-1);
    }
}
