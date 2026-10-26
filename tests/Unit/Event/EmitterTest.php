<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Event;

use Marcolin\SocketIO\Tests\Fake\TestEmitter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmitterTest extends TestCase
{
    public function testCallsListenersInRegistrationOrderWithTheArguments(): void
    {
        $emitter = $this->emitter();
        $calls = [];
        $emitter->on('data', function (string $a, int $b) use (&$calls): void {
            $calls[] = ['first', $a, $b];
        });
        $emitter->on('data', function (string $a, int $b) use (&$calls): void {
            $calls[] = ['second', $a, $b];
        });

        self::assertTrue($emitter->fire('data', 'x', 1));
        self::assertSame([['first', 'x', 1], ['second', 'x', 1]], $calls);
    }

    public function testReportsWhetherTheEventHadListeners(): void
    {
        self::assertFalse($this->emitter()->fire('nothing'));
    }

    public function testOnceListenersRunOnlyOnce(): void
    {
        $emitter = $this->emitter();
        $count = 0;
        $emitter->once('close', function () use (&$count): void {
            $count++;
        });

        $emitter->fire('close');
        self::assertFalse($emitter->fire('close'));
        self::assertSame(1, $count);
        self::assertSame([], $emitter->listeners('close'));
    }

    public function testOnceListenerFiresOnceUnderNestedDispatch(): void
    {
        $emitter = $this->emitter();
        $count = 0;
        $emitter->once('ping', function () use ($emitter): void {
            $emitter->fire('ping');
        });
        $emitter->once('ping', function () use (&$count): void {
            $count++;
        });

        // The nested dispatch fires the second listener; the outer one must not fire it again.
        $emitter->fire('ping');

        self::assertSame(1, $count);
    }

    public function testOffRemovesEveryRegistrationOfTheListener(): void
    {
        $emitter = $this->emitter();
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };
        $other = static function (): void {
        };
        $emitter->on('data', $listener)->once('data', $listener)->on('data', $other);

        $emitter->off('data', $listener);
        $emitter->fire('data');

        self::assertSame(0, $count);
        self::assertSame([$other], $emitter->listeners('data'));
    }

    public function testOffWithoutListenerRemovesAllListenersOfTheEvent(): void
    {
        $emitter = $this->emitter();
        $listener = static function (): void {
        };
        $emitter->on('a', $listener)->on('a', $listener)->on('b', $listener);

        $emitter->off('a');

        self::assertSame([], $emitter->listeners('a'));
        self::assertSame([$listener], $emitter->listeners('b'));
    }

    public function testOffOnUnknownEventIsANoop(): void
    {
        $emitter = $this->emitter();

        $emitter->off('unknown')->off('unknown', static function (): void {
        });

        self::assertSame([], $emitter->listeners('unknown'));
    }

    public function testListenersReturnsTheOriginalClosures(): void
    {
        $emitter = $this->emitter();
        $a = static function (): void {
        };
        $b = static function (): void {
        };

        $emitter->on('x', $a)->once('x', $b);

        self::assertSame([$a, $b], $emitter->listeners('x'));
    }

    public function testListenersRemovedDuringDispatchStillRunInThatDispatch(): void
    {
        $emitter = $this->emitter();
        $calls = [];
        $second = function () use (&$calls): void {
            $calls[] = 'second';
        };
        $emitter->on('x', function () use ($emitter, $second, &$calls): void {
            $calls[] = 'first';
            $emitter->off('x', $second);
        });
        $emitter->on('x', $second);

        $emitter->fire('x');
        $emitter->fire('x');

        self::assertSame(['first', 'second', 'first'], $calls);
    }

    public function testListenersAddedDuringDispatchRunFromTheNextDispatch(): void
    {
        $emitter = $this->emitter();
        $calls = 0;
        $emitter->once('x', function () use ($emitter, &$calls): void {
            $emitter->on('x', function () use (&$calls): void {
                $calls++;
            });
        });

        $emitter->fire('x');
        self::assertSame(0, $calls);

        $emitter->fire('x');
        self::assertSame(1, $calls);
    }

    public function testInterleavedEventsKeepTheirOwnOrder(): void
    {
        $emitter = new TestEmitter();
        $calls = [];
        foreach (['a1' => 'a', 'b1' => 'b', 'a2' => 'a', 'b2' => 'b'] as $name => $event) {
            $emitter->on($event, static function () use (&$calls, $name): void {
                $calls[] = $name;
            });
        }

        $emitter->fire('a');
        $emitter->fire('b');

        self::assertSame(['a1', 'a2', 'b1', 'b2'], $calls);
        self::assertSame(['a', 'b'], $emitter->eventNames());
    }

    public function testRemovingOneEventLeavesTheOthers(): void
    {
        $emitter = new TestEmitter();
        $listener = static function (): void {
        };
        $emitter->on('a', $listener);
        $emitter->once('b', $listener);
        $emitter->on('c', $listener);

        $emitter->off('b');

        self::assertSame(['a', 'c'], $emitter->eventNames());
        self::assertFalse($emitter->fire('b'));
        self::assertSame([$listener], $emitter->listeners('c'));
    }

    public function testExceptionsFromListenersPropagate(): void
    {
        $emitter = $this->emitter();
        $emitter->on('x', static function (): void {
            throw new RuntimeException('boom');
        });

        $this->expectExceptionObject(new RuntimeException('boom'));

        $emitter->fire('x');
    }

    public function testOnceListenerIsRemovedEvenWhenItThrows(): void
    {
        $emitter = $this->emitter();
        $emitter->once('x', static function (): void {
            throw new RuntimeException('boom');
        });

        try {
            $emitter->fire('x');
            self::fail('The exception should propagate');
        } catch (RuntimeException) {
        }

        self::assertSame([], $emitter->listeners('x'));
    }

    private function emitter(): TestEmitter
    {
        return new TestEmitter();
    }
}
