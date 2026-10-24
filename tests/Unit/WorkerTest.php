<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Engine\Protocol\HttpOrWebSocket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class WorkerTest extends TestCase
{
    public function testAPortCreatesAWorkerOnAllIpv4Interfaces(): void
    {
        $io = new SocketIO(3000, scheduler: new FakeScheduler());

        self::assertInstanceOf(Worker::class, $io->worker);
        self::assertSame('tcp://0.0.0.0:3000', $io->worker->getSocketName());
        self::assertSame(HttpOrWebSocket::class, $io->worker->protocol);
    }

    public function testWithoutAPortThereIsNoWorkerUntilAttached(): void
    {
        $io = new SocketIO(scheduler: new FakeScheduler());
        self::assertNull($io->worker);

        $worker = new Worker('tcp://[::]:3001');
        $io->attach($worker);

        self::assertSame($worker, $io->worker);
        self::assertSame(HttpOrWebSocket::class, $worker->protocol);
        self::assertNotNull($worker->onMessage);
        self::assertNotNull($worker->onWebSocketConnected);
    }

    public function testAcceptsATlsWorker(): void
    {
        $worker = new Worker('tcp://0.0.0.0:3443');
        $worker->transport = 'ssl';

        (new SocketIO(scheduler: new FakeScheduler()))->attach($worker);

        self::assertSame(HttpOrWebSocket::class, $worker->protocol);
    }

    public function testRejectsAWorkerThatIsNotTcp(): void
    {
        $worker = new Worker('udp://0.0.0.0:3002');
        $worker->transport = 'udp';

        $this->expectException(InvalidArgumentException::class);

        (new SocketIO(scheduler: new FakeScheduler()))->attach($worker);
    }

    public function testAttachesOnce(): void
    {
        $io = new SocketIO(3003, scheduler: new FakeScheduler());

        $this->expectException(LogicException::class);

        $io->attach(new Worker('tcp://0.0.0.0:3004'));
    }

    public function testEmitsWorkerStartAndStopAfterTheWorkersOwnCallbacks(): void
    {
        $calls = [];
        $worker = new Worker('tcp://0.0.0.0:3005');
        $worker->onWorkerStart = static function () use (&$calls): void {
            $calls[] = 'own start';
        };
        $worker->onWorkerStop = static function () use (&$calls): void {
            $calls[] = 'own stop';
        };
        $io = new SocketIO(scheduler: new FakeScheduler());
        $io->on('workerStart', static function (Worker $started) use (&$calls, $worker): void {
            $calls[] = $started === $worker ? 'start' : 'wrong worker';
        });
        $io->on('workerStop', static function () use (&$calls): void {
            $calls[] = 'stop';
        });
        $io->attach($worker);

        self::call($worker->onWorkerStart, $worker);
        self::call($worker->onWorkerStop, $worker);

        self::assertSame(['own start', 'start', 'own stop', 'stop'], $calls);
    }

    public function testEmitsWorkerEventsWithoutOwnCallbacks(): void
    {
        $started = 0;
        $worker = new Worker('tcp://0.0.0.0:3006');
        $io = new SocketIO(scheduler: new FakeScheduler());
        $io->on('workerStart', static function () use (&$started): void {
            $started++;
        });
        $io->attach($worker);

        self::call($worker->onWorkerStart, $worker);

        self::assertSame(1, $started);
    }

    /**
     * Runs a worker callback as Workerman would.
     */
    private static function call(mixed $callback, Worker $worker): void
    {
        self::assertIsCallable($callback);
        $callback($worker);
    }
}
