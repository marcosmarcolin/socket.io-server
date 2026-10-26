<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use ArrayObject;
use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\ConnectionStateRecovery;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionStateRecoveryTest extends TestCase
{
    private FakeScheduler $scheduler;

    private SocketIO $io;

    /** @var ArrayObject<int, Socket> the sockets that connected, in order */
    private ArrayObject $connected;

    /** @var ArrayObject<int, string> the sockets the middleware saw */
    private ArrayObject $checked;

    protected function setUp(): void
    {
        $this->start(true);
    }

    public function testTheConnectAnswerHasThePrivateId(): void
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');

        $connect = self::connectData($client->received()[0]);

        self::assertSame('000000000000000000000001', $connect['sid']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20}$/', $connect['pid']);
    }

    public function testWithoutTheOptionThereIsNoPrivateIdNorOffset(): void
    {
        $this->start(false);
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();

        $this->io->emit('news', 1);
        $this->socket(0)->emit('direct', 2);

        self::assertSame(['2["news",1]', '2["direct",2]'], $client->received());
    }

    public function testEventsGetAnOffsetAsTheirLastArgument(): void
    {
        [$client] = $this->connect();

        $this->io->emit('news', 1);
        $this->socket(0)->emit('direct', ['a' => 2]);

        $events = array_map(self::eventArgs(...), $client->received());
        self::assertSame(['news', 1], array_slice($events[0], 0, 2));
        self::assertSame(['direct', ['a' => 2]], array_slice($events[1], 0, 2));
        self::assertIsString($events[0][2]);
        self::assertIsString($events[1][2]);
        self::assertNotSame($events[0][2], $events[1][2]);
    }

    public function testAcknowledgementsAndVolatileEventsGetNoOffset(): void
    {
        [$client] = $this->connect();

        $this->socket(0)->emit('ask', 1, static function (): void {
        });
        $this->socket(0)->volatile->emit('tick', 2);
        $this->io->volatile->emit('tick', 3);

        self::assertSame(['20["ask",1]', '2["tick",2]', '2["tick",3]'], $client->received());
    }

    public function testOutgoingListenersSeeTheEventOnce(): void
    {
        [$client] = $this->connect();
        $seen = [];
        $this->socket(0)->onAnyOutgoing(static function (string $event, mixed ...$args) use (&$seen): void {
            $seen[] = [$event, $args[0]];
        });

        $this->socket(0)->emit('direct', 1);

        self::assertSame([['direct', 1]], $seen);
        self::assertCount(1, $client->received());
    }

    public function testAClientThatComesBackGetsItsSocketAndTheEventsItMissed(): void
    {
        [$client, $connect, $offset] = $this->connect();
        $socket = $this->socket(0);
        $socket->join('room');
        $socket->data['user'] = 'ana';
        $client->connection->close();

        $this->io->to('room')->emit('to-room', 1);
        $this->io->to('other')->emit('to-other', 2);
        $this->io->emit('to-all', 3);
        $this->io->except('room')->emit('to-all-but-room', 4);

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $received = $back->received();

        self::assertSame(['to-room', 'to-all'], array_map(
            static fn (string|object $message): mixed => self::eventArgs($message)[0],
            array_slice($received, 0, 2),
        ));
        // The missed events come first, so the client handles them as it connects.
        self::assertSame($connect, self::connectData($received[2]));
        $recovered = $this->socket(1);
        self::assertTrue($recovered->recovered);
        self::assertSame($socket->id, $recovered->id);
        self::assertSame(['user' => 'ana'], $recovered->data);
        self::assertEqualsCanonicalizing([$socket->id, 'room'], $recovered->rooms);
        // As the reference's default, the middlewares don't run again.
        self::assertSame([$socket->id], $this->checked->getArrayCopy());
    }

    public function testTheRecoveredSocketKeepsReceivingWhatItsRoomsGet(): void
    {
        [$client, $connect, $offset] = $this->connect();
        $this->socket(0)->join('room');
        $client->connection->close();
        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $back->received();

        $this->io->to('room')->emit('to-room', 1);

        self::assertSame('to-room', self::eventArgs($back->received()[0])[0]);
    }

    public function testTheMiddlewaresRunAgainWithoutSkipMiddlewares(): void
    {
        $this->start(['skipMiddlewares' => false]);
        [$client, $connect, $offset] = $this->connect();
        $client->connection->close();

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertTrue($this->socket(1)->recovered);
        self::assertSame([$connect['sid'], $connect['sid']], $this->checked->getArrayCopy());
    }

    public function testAnUnknownOffsetGetsANewSocket(): void
    {
        [$client, $connect] = $this->connect();
        $client->connection->close();

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => 'unknown']));

        self::assertFalse($this->socket(1)->recovered);
        self::assertNotSame($connect['sid'], self::connectData($back->received()[0])['sid']);
    }

    public function testAnUnknownPrivateIdGetsANewSocket(): void
    {
        [$client, , $offset] = $this->connect();
        $client->connection->close();

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => 'someone-else', 'offset' => $offset]));

        self::assertFalse($this->socket(1)->recovered);
    }

    public function testASessionExpiresAfterMaxDisconnectionDuration(): void
    {
        $this->start(['maxDisconnectionDuration' => 1000]);
        [$client, $connect, $offset] = $this->connect();
        $client->connection->close();

        $this->scheduler->elapse(1001);
        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertFalse($this->socket(1)->recovered);
    }

    public function testASessionIsStillThereJustBeforeItExpires(): void
    {
        $this->start(['maxDisconnectionDuration' => 1000]);
        [$client, $connect, $offset] = $this->connect();
        $client->connection->close();

        $this->scheduler->elapse(1000);
        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertTrue($this->socket(1)->recovered);
    }

    public function testEventsOlderThanMaxDisconnectionDurationAreDropped(): void
    {
        $this->start(['maxDisconnectionDuration' => 1000]);
        [$client, $connect, $offset] = $this->connect();
        $this->scheduler->elapse(600);
        $client->connection->close();

        // The event that carried the offset is now too old, so the client can't know what it missed.
        $this->scheduler->elapse(600);
        $this->io->emit('later', 1);
        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertFalse($this->socket(1)->recovered);
    }

    public function testASessionIsRecoveredOnce(): void
    {
        [$client, $connect, $offset] = $this->connect();
        $client->connection->close();
        $auth = '0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]);

        (new SocketIOClient($this->io))->send($auth);
        (new SocketIOClient($this->io))->send($auth);

        self::assertTrue($this->socket(1)->recovered);
        self::assertFalse($this->socket(2)->recovered);
    }

    public function testLeavingOnPurposeKeepsNoSession(): void
    {
        [$client, $connect, $offset] = $this->connect();
        $client->send('1');

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertFalse($this->socket(1)->recovered);
    }

    public function testADisconnectByTheServerKeepsNoSession(): void
    {
        [, $connect, $offset] = $this->connect();
        $this->socket(0)->disconnect();

        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertFalse($this->socket(1)->recovered);
    }

    public function testTheSessionKeepsTheRoomsTheSocketHadWhenItLeft(): void
    {
        [$client, $connect, $offset] = $this->connect();
        $this->socket(0)->join(['a', 'b']);
        $this->socket(0)->leave('b');
        $client->connection->close();

        $this->io->to('b')->emit('to-b', 1);
        $back = new SocketIOClient($this->io);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertEqualsCanonicalizing([$connect['sid'], 'a'], $this->socket(1)->rooms);
        // Only the CONNECT: the socket wasn't in "b".
        self::assertCount(1, $back->received());
    }

    public function testAClientBackOnTheSameProcessGetsWhatAnotherProcessBroadcast(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A');
        $b = $this->process($broker, 'B');
        [$client, $connect, $offset] = $this->connectTo($a);
        $a->sockets->sockets()[$connect['sid']]->join('room');
        $client->connection->close();

        // A has no member of "room" left, so it left the room's channel; the broadcast must reach it anyway.
        $b->to('room')->emit('from-b', 1);
        $b->to('other')->emit('not-for-it', 2);
        $back = new SocketIOClient($a);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));
        $received = $back->received();

        self::assertSame(['from-b', 1], array_slice(self::eventArgs($received[0]), 0, 2));
        self::assertSame($connect, self::connectData($received[1]));
        $socket = $a->sockets->sockets()[$connect['sid']] ?? self::fail('Not recovered');
        self::assertTrue($socket->recovered);
        self::assertEqualsCanonicalizing([$connect['sid'], 'room'], $socket->rooms);
    }

    public function testEveryProcessGetsTheSameOffset(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A');
        $b = $this->process($broker, 'B');
        [$onA] = $this->connectTo($a);
        [$onB] = $this->connectTo($b);

        $a->emit('news', 1);

        self::assertSame(self::eventArgs($onA->received()[0]), self::eventArgs($onB->received()[0]));
    }

    public function testAClientBackOnAnotherProcessStartsOver(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A');
        $b = $this->process($broker, 'B');
        [$client, $connect, $offset] = $this->connectTo($a);
        $client->connection->close();

        $back = new SocketIOClient($b);
        $back->send('0' . json_encode(['pid' => $connect['pid'], 'offset' => $offset]));

        self::assertNotSame($connect['sid'], self::connectData($back->received()[0])['sid']);
    }

    public function testWithoutTheOptionABroadcastToOneRoomStaysOnItsChannel(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A', false);
        $broker->published = [];

        $a->to('room')->emit('news', 1);

        self::assertSame(['socket.io#/#room#'], array_column($broker->published, 0));
    }

    /**
     * @return iterable<string, array{mixed, ?ConnectionStateRecovery}>
     */
    public static function options(): iterable
    {
        yield 'off by default' => [null, null];
        yield 'off' => [false, null];
        yield 'on, with the defaults' => [true, new ConnectionStateRecovery(120_000, true)];
        yield 'settings' => [
            ['maxDisconnectionDuration' => 5000, 'skipMiddlewares' => false],
            new ConnectionStateRecovery(5000, false),
        ];
        yield 'one setting' => [['maxDisconnectionDuration' => 5000], new ConnectionStateRecovery(5000, true)];
    }

    #[DataProvider('options')]
    public function testReadsTheOption(mixed $value, ?ConnectionStateRecovery $expected): void
    {
        self::assertEquals($expected, ConnectionStateRecovery::fromOption($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'a string' => ['yes'];
        yield 'an unknown setting' => [['maxDuration' => 5000]];
        yield 'a duration that is not an integer' => [['maxDisconnectionDuration' => '5000']];
        yield 'a duration of zero' => [['maxDisconnectionDuration' => 0]];
        yield 'skipMiddlewares not a boolean' => [['skipMiddlewares' => 1]];
    }

    #[DataProvider('invalidOptions')]
    public function testRefusesInvalidOptions(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocketIO(options: ['connectionStateRecovery' => $value], scheduler: new FakeScheduler());
    }

    private function process(FakeChannelBroker $broker, string $name, mixed $recovery = true): SocketIO
    {
        $io = new SocketIO(
            options: ['connectionStateRecovery' => $recovery, 'pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($name === 'A' ? 0x100 : 0x200),
        );
        $pubSub = $broker->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $pubSub));

        return $io;
    }

    /**
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

    private function start(mixed $recovery): void
    {
        $this->scheduler = new FakeScheduler();
        $this->io = new SocketIO(
            options: ['connectionStateRecovery' => $recovery],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->connected = new ArrayObject();
        $this->checked = new ArrayObject();
        $connected = $this->connected;
        $checked = $this->checked;
        $this->io->use(static function (Socket $socket, Closure $next) use ($checked): void {
            $checked->append($socket->id);
            $next();
        });
        $this->io->on('connection', static function (Socket $socket) use ($connected): void {
            $connected->append($socket);
        });
    }

    /**
     * Connects a client to "/" and has the server send it one event, so the client knows an offset.
     *
     * @return array{SocketIOClient, array{sid: string, pid: string}, string}
     */
    private function connect(): array
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $connect = self::connectData($client->received()[0]);
        $this->io->emit('hello');
        $offset = self::eventArgs($client->received()[0])[1];
        self::assertIsString($offset);

        return [$client, $connect, $offset];
    }

    private function socket(int $index): Socket
    {
        return $this->connected[$index] ?? self::fail('No socket connected at ' . $index);
    }

    /**
     * @return array{sid: string, pid: string}
     */
    private static function connectData(string|object $message): array
    {
        self::assertIsString($message);
        self::assertStringStartsWith('0{', $message);
        $data = json_decode(substr($message, 1), true);
        self::assertIsArray($data);
        self::assertIsString($data['sid'] ?? null);
        self::assertIsString($data['pid'] ?? null);

        return ['sid' => $data['sid'], 'pid' => $data['pid']];
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
