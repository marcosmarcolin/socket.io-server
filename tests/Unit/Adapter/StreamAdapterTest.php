<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use Closure;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\Appender;
use Marcolin\SocketIO\Adapter\RedisStreamsAdapter;
use Marcolin\SocketIO\Emitter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\FakeStreamBroker;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * PubSubAdapter with a Stream, as RedisStreamsAdapter runs it: processes share a fake channel broker and a fake
 * stream. The cluster protocol over the channels is ClusterTest's.
 */
final class StreamAdapterTest extends TestCase
{
    private FakeScheduler $clock;

    private FakeChannelBroker $channels;

    private FakeStreamBroker $stream;

    protected function setUp(): void
    {
        $this->clock = new FakeScheduler();
        $this->channels = new FakeChannelBroker();
        $this->stream = new FakeStreamBroker($this->clock);
    }

    public function testABroadcastGoesThroughTheStreamToEveryProcess(): void
    {
        $a = $this->process('A');
        $b = $this->process('B');
        [$inRoom, $socket] = $this->join($b);
        $socket->join('room');
        [$elsewhere] = $this->join($b);

        $a->to('room')->emit('news', 1);

        self::assertSame(['2["news",1]'], $inRoom->received());
        self::assertSame([], $elsewhere->received());
        self::assertSame([3], array_column(array_column($this->stream->entries, 2), 'type'));
        self::assertNotContains(3, array_column(array_column($this->channels->published, 1), 'type'));
    }

    public function testNoRoomGetsAChannel(): void
    {
        $b = $this->process('B');
        [, $socket] = $this->join($b);

        $socket->join('room');

        self::assertNotContains('socket.io#/#room#', $this->channels->channelsOf('B'));
    }

    public function testTheSocketHelpersGoThroughTheStream(): void
    {
        $a = $this->process('A');
        $b = $this->process('B');
        [$client, $socket] = $this->join($b);

        $a->socketsJoin('room');
        $a->to('room')->disconnectSockets(true);

        self::assertTrue($client->isClosed());
        self::assertFalse($socket->connected);
        self::assertSame([4, 6], array_column(array_column($this->stream->entries, 2), 'type'));
    }

    public function testRequestsAndHeartbeatsStayOnTheChannels(): void
    {
        $a = $this->process('A');
        $this->process('B');
        $answers = null;

        $a->serverSideEmit('ping', static function (?\Throwable $error, array $responses) use (&$answers): void {
            $answers = $responses;
        });
        $this->clock->advance(5000);

        self::assertSame([], $this->stream->entries);
        self::assertSame([], $answers);
        self::assertContains(9, array_column(array_column($this->channels->published, 1), 'type'));
    }

    public function testAProcessThatLostTheStreamReadsWhatItMissed(): void
    {
        $a = $this->process('A');
        $b = $this->process('B');
        [$client] = $this->join($b);
        $this->stream->goOffline('B');

        $a->emit('first');
        $a->emit('second');
        self::assertSame([], $client->received());

        $this->stream->comeBack('B');

        self::assertSame(['2["first"]', '2["second"]'], $client->received());
    }

    public function testTheOffsetIsTheEntryIdOnEveryProcess(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$onA] = $this->join($a);
        [$onB] = $this->join($b);

        $a->emit('news', 1);

        $id = $this->stream->entries[0][0];
        self::assertSame(['2["news",1,"' . $id . '"]'], $onA->received());
        self::assertSame(['2["news",1,"' . $id . '"]'], $onB->received());
    }

    public function testAClientRecoversOnAnotherProcess(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $socket = $a->sockets->sockets()[$connect['sid']] ?? self::fail('Not connected');
        $socket->join('room');
        $socket->data['user'] = 'ana';
        $client->connection->close();

        $b->to('room')->emit('to-room', 1);
        $b->to('other')->emit('to-other', 2);
        $a->emit('to-all', 3);
        $a->except('room')->emit('to-all-but-room', 4);
        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $received = $back->received();

        self::assertSame([['to-room', 1], ['to-all', 3]], array_map(
            static fn (string|object $message): array => array_slice(self::eventArgs($message), 0, 2),
            array_slice($received, 0, 2),
        ));
        self::assertSame($connect, self::connectData($received[2]));
        $recovered = $b->sockets->sockets()[$connect['sid']] ?? self::fail('Not recovered');
        self::assertTrue($recovered->recovered);
        self::assertSame(['user' => 'ana'], $recovered->data);
        self::assertEqualsCanonicalizing([$connect['sid'], 'room'], $recovered->rooms);
    }

    public function testTheMissedEventsKeepTheirOffsets(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();

        $a->emit('missed');
        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        $id = $this->stream->entries[count($this->stream->entries) - 1][0] ?? self::fail('Nothing appended');
        self::assertSame('2["missed","' . $id . '"]', $back->received()[0]);
    }

    public function testALocalBroadcastReachesOnlyItsProcessAndItsSessions(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$fromA, $connectA, $offsetA] = $this->connectTo($a);
        [$fromB, $connectB, $offsetB] = $this->connectTo($b);
        [$stillOnB] = $this->join($b);
        $fromA->connection->close();
        $fromB->connection->close();

        $a->local->emit('local-a');
        self::assertSame([], $stillOnB->received());

        // Each comes back on the other process: only A's socket would have got it.
        $toB = new SocketIOClient($b);
        $toB->send('0' . json_encode(['pid' => $connectA['pid'], 'offset' => $offsetA]));
        $toA = new SocketIOClient($a);
        $toA->send('0' . json_encode(['pid' => $connectB['pid'], 'offset' => $offsetB]));

        self::assertSame('local-a', self::eventArgs($toB->received()[0])[0]);
        self::assertSame($connectB, self::connectData($toA->received()[0]));
    }

    public function testASocketsOwnEmitIsRecoveredOnAnotherProcess(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        ($a->sockets->sockets()[$connect['sid']] ?? self::fail('Not connected'))->emit('direct');
        $client->connection->close();

        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertSame('direct', self::eventArgs($back->received()[0])[0]);
    }

    public function testLocalDeliveryWaitsForTheIdAndKeepsTheOrder(): void
    {
        $a = $this->process('A', true);
        [$client] = $this->join($a);
        $this->stream->hold();

        $a->emit('first');
        $a->volatile->emit('second');
        $a->local->volatile->emit('third');
        self::assertSame([], $client->received());

        $this->stream->release();

        self::assertSame(['first', 'second', 'third'], $this->eventNames($client->received()));
    }

    public function testWithoutAnIdTheEventArrivesWithoutAnOffset(): void
    {
        $a = $this->process('A', true);
        [$client] = $this->join($a);
        $this->stream->failAppends = true;

        $a->emit('news', 1);

        self::assertSame(['2["news",1]'], $client->received());
    }

    public function testAcknowledgementsAndVolatileEventsGetNoOffset(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client] = $this->join($b);

        $a->volatile->emit('volatile');
        $a->timeout(1000)->emit('asked', static function (): void {
        });

        self::assertSame(['2["volatile"]', '20["asked"]'], $client->received());
    }

    public function testASessionIsRecoveredOnce(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();
        $auth = '0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]);
        $first = new SocketIOClient($b);
        $first->send($auth);
        $second = new SocketIOClient($a);

        $second->send($auth);

        self::assertNotSame($connect['sid'], self::connectData($second->received()[0])['sid']);
    }

    public function testAnOffsetNoLongerInTheStreamGetsANewSocket(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();
        $a->emit('news');
        $this->stream->trim(1);

        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertNotSame($connect['sid'], self::connectData($back->received()[0])['sid']);
    }

    public function testAnExpiredSessionGetsANewSocket(): void
    {
        $a = $this->process('A', ['maxDisconnectionDuration' => 1000]);
        $b = $this->process('B', ['maxDisconnectionDuration' => 1000]);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();
        $this->clock->elapse(1001);

        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertNotSame($connect['sid'], self::connectData($back->received()[0])['sid']);
    }

    public function testAClientThatLeftDuringTheRestoreIsDropped(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();
        $this->stream->deferRestores = true;
        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $back->connection->close();

        $this->stream->finishRestores();

        self::assertSame([], $b->sockets->sockets());
    }

    public function testARestoreThatAnswersLaterConnects(): void
    {
        $a = $this->process('A', true);
        $b = $this->process('B', true);
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();
        $this->stream->deferRestores = true;
        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $before = $back->received();

        $this->stream->finishRestores();

        self::assertSame([], $before);
        self::assertSame($connect, self::connectData($back->received()[0]));
    }

    public function testWithoutRecoveryTheStreamKeepsNoSessionsNorOffsets(): void
    {
        $a = $this->process('A');
        $b = $this->process('B');
        [$client] = $this->join($b);

        $a->emit('news');

        self::assertSame(['2["news"]'], $client->received());
        self::assertArrayNotHasKey('recoverable', $this->stream->entries[0][2]);
    }

    public function testTheEmitterAppendsWhatServersRecover(): void
    {
        $b = $this->process('B', true);
        [$client] = $this->join($b);
        $stream = $this->stream;
        $appender = new class ($stream) implements Appender {
            public function __construct(private readonly FakeStreamBroker $stream)
            {
            }

            public function append(string $nsp, array $message): void
            {
                $this->stream->append($nsp, $message, static function (): void {
                });
            }
        };
        $emitter = new Emitter($this->channels->connect('php-fpm'), 'socket.io', $appender);

        $emitter->emit('news', 1);
        $emitter->volatile->emit('volatile');
        $emitter->serverSideEmit('hello');

        $id = $this->stream->entries[0][0];
        self::assertSame(['2["news",1,"' . $id . '"]', '2["volatile"]'], $client->received());
        self::assertCount(2, $this->stream->entries);
    }

    public function testClosingStopsReadingTheStream(): void
    {
        $a = $this->process('A');
        $b = $this->process('B');
        [$client] = $this->join($b);

        $b->close();
        $a->emit('news');

        self::assertSame([], $client->received());
    }

    private function process(string $name, mixed $recovery = false): SocketIO
    {
        $io = new SocketIO(
            options: ['connectionStateRecovery' => $recovery, 'pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: $this->clock,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($name === 'A' ? 0x100 : 0x200),
        );
        $pubSub = $this->channels->connect($name);
        $stream = $this->stream->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new RedisStreamsAdapter($nsp, $pubSub, 'socket.io', $stream));

        return $io;
    }

    /**
     * A client connected to "/", and its socket.
     *
     * @return array{SocketIOClient, Socket}
     */
    private function join(SocketIO $io): array
    {
        $client = new SocketIOClient($io);
        $client->send('0');
        $sid = self::sid(self::connectAnswer($client->received()[0]));

        return [$client, $io->sockets->sockets()[$sid] ?? self::fail('Not connected')];
    }

    /**
     * Connects a client and has its socket send it one event, so the client knows an offset.
     *
     * @return array{SocketIOClient, array{sid: string, pid: string}, string}
     */
    private function connectTo(SocketIO $io): array
    {
        $client = new SocketIOClient($io);
        $client->send('0');
        $connect = self::connectData($client->received()[0]);
        ($io->sockets->sockets()[$connect['sid']] ?? self::fail('Not connected'))->emit('hello');
        $offset = self::eventArgs($client->received()[0])[1];
        self::assertIsString($offset);

        return [$client, $connect, $offset];
    }

    /**
     * @param list<string|object> $messages
     * @return list<mixed>
     */
    private function eventNames(array $messages): array
    {
        return array_map(static fn (string|object $message): mixed => self::eventArgs($message)[0], $messages);
    }

    /**
     * @return array{sid: string, pid: string}
     */
    private static function connectData(string|object $message): array
    {
        $data = self::connectAnswer($message);
        $pid = $data['pid'] ?? null;
        self::assertIsString($pid);

        return ['sid' => self::sid($data), 'pid' => $pid];
    }

    /**
     * @return array<mixed>
     */
    private static function connectAnswer(string|object $message): array
    {
        self::assertIsString($message);
        self::assertStringStartsWith('0{', $message);
        $data = json_decode(substr($message, 1), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function sid(array $data): string
    {
        $sid = $data['sid'] ?? null;
        self::assertIsString($sid);

        return $sid;
    }

    /**
     * @return list<mixed>
     */
    private static function eventArgs(string|object $message): array
    {
        self::assertIsString($message);
        self::assertStringStartsWith('2[', $message);
        $args = json_decode(substr($message, 1), true);
        self::assertIsArray($args);

        return array_values($args);
    }
}
