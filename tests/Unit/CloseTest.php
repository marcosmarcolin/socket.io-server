<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use ArrayObject;
use Closure;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use Marcolin\SocketIO\Tests\Fake\SpyWorker;
use PHPUnit\Framework\TestCase;

final class CloseTest extends TestCase
{
    private SocketIO $io;

    /** @var ArrayObject<int, string> */
    private ArrayObject $events;

    /** @var ArrayObject<int, string> */
    private ArrayObject $closedAdapters;

    protected function setUp(): void
    {
        $this->events = new ArrayObject();
        $this->closedAdapters = new ArrayObject();
        $this->io = new SocketIO(
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $closed = $this->closedAdapters;
        $this->io->adapter(static fn (Nsp $nsp): Adapter => new class ($nsp, $closed) extends InMemoryAdapter {
            /**
             * @param ArrayObject<int, string> $closed
             */
            public function __construct(Nsp $nsp, private readonly ArrayObject $closed)
            {
                parent::__construct($nsp);
            }

            public function close(): void
            {
                $this->closed->append($this->nsp->name);
            }
        });
        $events = $this->events;
        $record = static function (Socket $socket) use ($events): void {
            foreach (['disconnecting', 'disconnect'] as $event) {
                $socket->on($event, static function (string $reason) use ($events, $event, $socket): void {
                    $events->append("{$socket->nsp->name} {$event}: {$reason}");
                });
            }
        };
        $this->io->on('connection', $record);
        $this->io->of('/admin')->on('connection', $record);
    }

    public function testEverySocketLeavesWithServerShuttingDown(): void
    {
        $client = $this->connect('/');
        $client->send('0/admin,');
        $client->received();

        $this->io->close();

        self::assertSame([
            '/ disconnecting: server shutting down',
            '/ disconnect: server shutting down',
            '/admin disconnecting: server shutting down',
            '/admin disconnect: server shutting down',
        ], $this->events->getArrayCopy());
        self::assertSame([], $this->io->sockets->sockets());
        self::assertSame([], $this->io->of('/admin')->sockets());
    }

    public function testSessionsCloseAtOnceWithoutADisconnectPacket(): void
    {
        $client = $this->connect('/');
        $this->io->emit('buffered but dropped');
        $client->connection->sent = [];

        $this->io->close();

        // As in the reference: no DISCONNECT, the transport just closes.
        self::assertSame([], $client->received());
        self::assertTrue($client->isClosed());
        self::assertSame(0, $this->io->engine->sessionCount());
    }

    public function testAPendingPollGetsTheClosePacket(): void
    {
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling'));
        $poll = FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling&sid=sid1');
        $this->io->engine->handleRequest($poll);

        $this->io->close();

        self::assertSame('1', $poll->responseBody);
        self::assertSame(0, $this->io->engine->sessionCount());
    }

    public function testEveryAdapterCloses(): void
    {
        $this->io->of('/admin');

        $this->io->close();

        self::assertSame(['/', '/admin'], $this->closedAdapters->getArrayCopy());
    }

    public function testASocketStillInItsMiddlewaresNeverConnects(): void
    {
        $pending = null;
        $this->io->use(static function (Socket $socket, Closure $next) use (&$pending): void {
            $pending = $next;
        });
        $client = new SocketIOClient($this->io);
        $client->send('0');

        $this->io->close();
        self::assertInstanceOf(Closure::class, $pending);
        $pending();

        self::assertSame([], $this->io->sockets->sockets());
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testTheWorkerStopsAcceptingConnections(): void
    {
        $worker = new SpyWorker('tcp://0.0.0.0:3010');
        $this->io->attach($worker);

        $this->io->close();

        self::assertSame(1, $worker->unlistened);
    }

    public function testClosingTwiceIsHarmless(): void
    {
        $this->connect('/');

        $this->io->close();
        $this->io->close();

        self::assertCount(2, $this->events);
    }

    private function connect(string $namespace): SocketIOClient
    {
        $client = new SocketIOClient($this->io);
        $client->send($namespace === '/' ? '0' : "0{$namespace},");
        $client->received();

        return $client;
    }
}
