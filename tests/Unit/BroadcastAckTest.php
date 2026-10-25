<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use Marcolin\SocketIO\TimeoutException;
use PHPUnit\Framework\TestCase;
use WeakReference;

/**
 * Broadcasts whose callback gets every client's answer, as the reference's BroadcastOperator::emit() with an ack.
 */
final class BroadcastAckTest extends TestCase
{
    private FakeScheduler $scheduler;

    private SocketIO $io;

    /** @var list<SocketIOClient> */
    private array $clients = [];

    /** @var list<Socket> */
    private array $sockets = [];

    // The sessions' own timers, such as their pings.
    private int $sessionTimers = 0;

    /** @var list<array{?TimeoutException, list<mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        // Three sockets: 0 and 1 in room "a".
        for ($i = 0; $i < 3; $i++) {
            $this->connect();
        }
        $this->sockets[0]->join('a');
        $this->sockets[1]->join('a');
        $this->sessionTimers = $this->scheduler->pending();
    }

    public function testCollectsOneAnswerPerClientInArrivalOrder(): void
    {
        $this->io->timeout(1000)->emit('ask', 1, $this->collect());

        foreach ($this->clients as $client) {
            self::assertSame(['20["ask",1]'], $client->received());
        }
        $this->clients[2]->send('30["c"]');
        $this->clients[0]->send('30[{"n":0}]');
        self::assertSame([], $this->calls);
        $this->clients[1]->send('30["b"]');

        self::assertSame([[null, ['c', ['n' => 0], 'b']]], $this->calls);
        self::assertSame($this->sessionTimers, $this->scheduler->pending());
    }

    public function testOnlyTheFirstArgumentOfEachAnswerCounts(): void
    {
        $this->io->to('a')->timeout(1000)->emit('ask', $this->collect());

        $this->clients[0]->send('30["x","y"]');
        $this->clients[1]->send('30[]');

        self::assertSame([[null, ['x', null]]], $this->calls);
    }

    public function testAClientAnsweringTwiceCountsOnce(): void
    {
        $this->io->to('a')->timeout(1000)->emit('ask', $this->collect());

        $this->clients[0]->send('30["first"]');
        $this->clients[0]->send('30["again"]');

        self::assertSame([], $this->calls);
        $this->clients[1]->send('30["b"]');
        self::assertSame([[null, ['first', 'b']]], $this->calls);
    }

    public function testTimesOutWithTheAnswersSoFar(): void
    {
        $this->io->timeout(1000)->emit('ask', $this->collect());
        $this->clients[1]->send('30["b"]');

        $this->scheduler->advance(999);
        $before = $this->calls;
        $this->scheduler->advance(1);

        self::assertSame([], $before);
        self::assertEquals([[new TimeoutException('operation has timed out'), ['b']]], $this->calls);
    }

    public function testLateAnswersAreIgnoredAfterTheTimeout(): void
    {
        $this->io->timeout(1000)->emit('ask', $this->collect());
        $this->scheduler->advance(1000);

        foreach ($this->clients as $client) {
            $client->send('30["late"]');
        }

        self::assertCount(1, $this->calls);
        self::assertSame([], $this->calls[0][1]);
    }

    public function testATimedOutBroadcastReleasesItsCallback(): void
    {
        $callback = static function (): void {
        };
        $reference = WeakReference::create($callback);
        $this->io->timeout(1000)->emit('ask', $callback);
        unset($callback);

        $this->scheduler->advance(1000);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    public function testAVolatileBroadcastSkipsABusyTransportAndTimesOut(): void
    {
        $poll = '/socket.io/?EIO=4&transport=polling&sid=sid4';
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling'));
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('POST', $poll, '40'));
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', $poll));

        $this->io->volatile->timeout(1000)->emit('ask', $this->collect());
        foreach ($this->clients as $index => $client) {
            $client->send('30["' . $index . '"]');
        }
        $next = FakeHttpExchange::fromUrl('GET', $poll);
        $this->io->engine->handleRequest($next);
        $this->scheduler->advance(1000);

        self::assertNull($next->responseBody);
        self::assertEquals([[new TimeoutException('operation has timed out'), ['0', '1', '2']]], $this->calls);
    }

    public function testNoSocketAnswersAtOnce(): void
    {
        $this->io->to('nobody')->timeout(1000)->emit('ask', $this->collect());

        self::assertSame([[null, []]], $this->calls);
        self::assertSame($this->sessionTimers, $this->scheduler->pending());
    }

    public function testFollowsTheRoomsAndLeavesTheSenderOut(): void
    {
        $this->io->except('a')->timeout(1000)->emit('ask', $this->collect());
        $this->sockets[0]->broadcast->timeout(1000)->emit('ask', $this->collect());

        self::assertSame([], $this->clients[0]->received());
        self::assertSame(['21["ask"]'], $this->clients[1]->received());
        self::assertSame(['20["ask"]', '21["ask"]'], $this->clients[2]->received());
        $this->clients[2]->send('30["only"]');
        self::assertSame([[null, ['only']]], $this->calls);
    }

    public function testEachBroadcastHasItsOwnIdAmongTheNamespaceAcks(): void
    {
        $this->sockets[0]->emit('single', static function (): void {
        });
        $this->io->to('a')->timeout(1000)->emit('ask', $this->collect());

        self::assertSame(['20["single"]', '21["ask"]'], $this->clients[0]->received());
        $this->clients[0]->send('31["a0"]');
        $this->clients[1]->send('31["a1"]');
        self::assertSame([[null, ['a0', 'a1']]], $this->calls);
    }

    public function testOtherNamespacesHaveTheirOwnSockets(): void
    {
        $custom = $this->io->of('/custom');
        $client = new SocketIOClient($this->io);
        $client->send('0/custom,');
        $client->received();

        $custom->timeout(1000)->emit('ask', $this->collect());

        self::assertSame(['2/custom,0["ask"]'], $client->received());
        $client->send('3/custom,0["yes"]');
        self::assertSame([[null, ['yes']]], $this->calls);
    }

    public function testNeedsATimeout(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs timeout()');

        $this->io->to('a')->emit('ask', static function (): void {
        });
    }

    public function testRefusesANegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->io->timeout(-1);
    }

    public function testModifiersKeepTheTimeout(): void
    {
        $this->io->timeout(1000)->to('a')->except('nobody')->volatile->emit('ask', $this->collect());

        $this->clients[0]->send('30["a"]');
        $this->clients[1]->send('30["b"]');

        self::assertSame([[null, ['a', 'b']]], $this->calls);
    }

    public function testAnEmitWithoutCallbackIgnoresTheTimeout(): void
    {
        $this->io->timeout(1000)->emit('news');

        self::assertSame(['2["news"]'], $this->clients[0]->received());
        self::assertSame($this->sessionTimers, $this->scheduler->pending());
    }

    // Helpers

    private function collect(): Closure
    {
        return function (?TimeoutException $error, array $responses): void {
            $this->calls[] = [$error, array_values($responses)];
        };
    }

    private function connect(): void
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();
        $sockets = $this->io->sockets->sockets();
        $this->clients[] = $client;
        $this->sockets[] = $sockets[array_key_last($sockets)];
    }
}
