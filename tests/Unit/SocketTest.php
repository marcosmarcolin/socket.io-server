<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use ArrayObject;
use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use Marcolin\SocketIO\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

final class SocketTest extends TestCase
{
    private const POLL_SID2 = '/socket.io/?EIO=4&transport=polling&sid=sid2';

    private FakeScheduler $scheduler;

    private SocketIO $io;

    private SocketIOClient $client;

    private Socket $socket;

    /** @var list<string> EVENT packets answered to the polls of pollingSocket() */
    private array $polled = [];

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->io = new SocketIO(
            ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            $this->scheduler,
            new SequentialSessionIdGenerator(),
            new SequentialSocketIdGenerator(),
        );
        [$this->client, $this->socket] = $this->connect();
    }

    // Identity and handshake

    public function testHasItsOwnIdAndRoom(): void
    {
        self::assertSame('000000000000000000000001', $this->socket->id);
        self::assertNotSame($this->socket->conn->id, $this->socket->id);
        self::assertSame(['000000000000000000000001'], $this->socket->rooms);
        self::assertTrue($this->socket->connected);
        self::assertFalse($this->socket->disconnected);
    }

    public function testEachNamespaceGetsAnotherId(): void
    {
        $this->io->of('/admin');

        $this->client->send('0/admin,');

        $sockets = $this->io->of('/admin')->sockets();
        self::assertSame(['000000000000000000000002'], array_keys($sockets));
    }

    public function testDescribesTheHandshake(): void
    {
        $io = new SocketIO([], $this->scheduler, new SequentialSessionIdGenerator(), new SequentialSocketIdGenerator());
        $client = new SocketIOClient($io, ['Origin' => 'https://a.test', 'X-Token' => 'abc'], 'room=lobby');
        $sockets = [];
        $io->on('connection', function (Socket $socket) use (&$sockets): void {
            $sockets[] = $socket;
        });

        $client->send('0{"token":"123"}');

        $handshake = $sockets[0]->handshake;
        self::assertSame('https://a.test', $handshake['headers']['origin'] ?? null);
        self::assertSame('abc', $handshake['headers']['x-token'] ?? null);
        self::assertSame('10.0.0.1', $handshake['address']);
        self::assertTrue($handshake['xdomain']);
        self::assertFalse($handshake['secure']);
        self::assertSame('/socket.io/?EIO=4&transport=websocket&room=lobby', $handshake['url']);
        self::assertSame(['EIO' => '4', 'transport' => 'websocket', 'room' => 'lobby'], $handshake['query']);
        self::assertSame(['token' => '123'], $handshake['auth']);
        self::assertEqualsWithDelta(microtime(true) * 1000, $handshake['issued'], 5000);
        self::assertNotSame('', $handshake['time']);
        self::assertSame($handshake['url'], $sockets[0]->request->uri);
    }

    public function testThePollingHandshakeRequestIsKept(): void
    {
        $io = new SocketIO([], $this->scheduler, new SequentialSessionIdGenerator(), new SequentialSocketIdGenerator());
        $io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling&x=1'));

        $session = $io->engine->session('sid1');

        self::assertInstanceOf(Request::class, $session?->request);
        self::assertSame('/socket.io/?EIO=4&transport=polling&x=1', $session->request->uri);
        self::assertSame('1', $session->request->query('x'));
    }

    public function testUnknownPropertiesThrow(): void
    {
        $this->expectException(LogicException::class);

        $this->socket->__get('nope');
    }

    // Emitting

    public function testEmitsEventsWithAnyData(): void
    {
        $this->socket->emit('hello', 'world', 1, ['a' => true], new stdClass(), null);
        $this->socket->send('hi');

        self::assertSame(
            ['2["hello","world",1,{"a":true},{},null]', '2["message","hi"]'],
            $this->client->received(),
        );
    }

    public function testEmitsBinaryAsAttachments(): void
    {
        $this->socket->emit('file', new Binary("\x00\xff"));

        self::assertEquals(
            ['51-["file",{"_placeholder":true,"num":0}]', new Binary("\x00\xff")],
            $this->client->received(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        foreach (Packet::RESERVED_EVENTS as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('reservedNames')]
    public function testRefusesToEmitReservedNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->socket->emit($name);
    }

    public function testGetsTheAcknowledgementOfAnEmit(): void
    {
        $answers = self::log();

        $this->socket->emit('ask', 1, function (mixed ...$args) use ($answers): void {
            $answers->append($args);
        });
        $this->socket->emit('ask', 2, function (mixed ...$args) use ($answers): void {
            $answers->append($args);
        });
        $this->client->send('31["second"]');
        $this->client->send('30["first",{"x":1}]');
        $this->client->send('30["again"]');

        self::assertSame(['20["ask",1]', '21["ask",2]'], $this->client->received());
        self::assertSame([['second'], ['first', ['x' => 1]]], $answers->getArrayCopy());
    }

    public function testATimeoutPassesNullThenTheAnswer(): void
    {
        $answers = self::log();

        $this->socket->timeout(1000)->emit('ask', function (?Throwable $error, mixed $answer) use ($answers): void {
            $answers->append([$error, $answer]);
        });
        $this->scheduler->advance(999);
        $this->client->send('30["yes"]');
        $this->scheduler->advance(10);

        self::assertSame([[null, 'yes']], $answers->getArrayCopy());
        // The answer cancelled the timeout; only the heartbeat timer is left.
        self::assertSame(1, $this->scheduler->pending());
    }

    public function testATimeoutPassesATimeoutException(): void
    {
        $answers = self::log();

        $this->socket->timeout(1000)->emit('ask', function (?Throwable $error, mixed $answer) use ($answers): void {
            $answers->append([$error, $answer]);
        });
        $this->scheduler->advance(1000);
        $this->client->send('30["too late"]');

        self::assertCount(1, $answers);
        $call = $answers[0];
        self::assertIsArray($call);
        self::assertInstanceOf(TimeoutException::class, $call[0] ?? null);
        self::assertSame('operation has timed out', $call[0]->getMessage());
        self::assertArrayHasKey(1, $call);
        self::assertNull($call[1]);
    }

    public function testEmitterModifiersAreImmutable(): void
    {
        $emitter = $this->socket->timeout(1000);

        self::assertNotSame($emitter, $emitter->timeout(5));
        self::assertNotSame($emitter, $emitter->volatile);
        self::assertSame($emitter, $emitter->compress(false));

        $this->socket->compress(false)->emit('plain');
        self::assertSame(['2["plain"]'], $this->client->received());
    }

    public function testRejectsANegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->socket->timeout(-1);
    }

    public function testVolatileEmitsAreDroppedWhenTheTransportIsBusy(): void
    {
        [$socket, $poll] = $this->pollingSocket();

        // No GET is pending: a volatile emit is dropped, a normal one waits in the buffer.
        $socket->volatile->emit('dropped');
        $socket->emit('kept');
        $poll();
        self::assertSame(['42["kept"]'], $this->polled);

        $pending = FakeHttpExchange::fromUrl('GET', self::POLL_SID2);
        $this->io->engine->handleRequest($pending);
        $socket->volatile->emit('sent');

        self::assertSame('42["sent"]', $pending->responseBody);
    }

    // Receiving

    public function testListenersGetTheArgumentsAndAnAckClosure(): void
    {
        $calls = self::log();
        $this->socket->on('chat', function (mixed ...$args) use ($calls): void {
            $last = $args === [] ? null : $args[array_key_last($args)];
            if ($last instanceof Closure) {
                array_pop($args);
                $last('ok', 2);
                $last('twice');
            }
            $calls->append([$args, $last instanceof Closure]);
        });

        $this->client->send('2["chat","hi"]');
        $this->client->send('27["chat","with ack"]');

        self::assertSame([[['hi'], false], [['with ack'], true]], $calls->getArrayCopy());
        self::assertSame(['37["ok",2]'], $this->client->received());
    }

    public function testNumericEventNamesBecomeStrings(): void
    {
        $calls = self::log();
        $this->socket->on('42', function (string $value) use ($calls): void {
            $calls->append($value);
        });

        $this->client->send('2[42,"answer"]');

        self::assertSame(['answer'], $calls->getArrayCopy());
    }

    public function testAnInvalidPacketIsAnErrorAndClosesTheConnection(): void
    {
        $errors = self::log();
        $reasons = self::log();
        $this->socket->on('error', function (Throwable $error) use ($errors): void {
            $errors->append($error);
        });
        $this->socket->on('disconnect', function (string $reason) use ($reasons): void {
            $reasons->append($reason);
        });

        $this->client->send('2{}');

        self::assertInstanceOf(InvalidPacketException::class, $errors[0] ?? null);
        self::assertTrue($this->client->isClosed());
        self::assertSame(['forced close'], $reasons->getArrayCopy());
    }

    public function testASecondConnectToTheSameNamespaceClosesTheConnection(): void
    {
        $this->client->send('0');

        self::assertTrue($this->client->isClosed());
    }

    public function testAPacketForAnotherNamespaceClosesTheConnection(): void
    {
        $this->client->send('2/admin,["hi"]');

        self::assertTrue($this->client->isClosed());
    }

    // Rooms

    public function testJoinsAndLeavesRooms(): void
    {
        $this->socket->join('a')->join(['b', 'c', 'a']);
        $this->socket->leave('b');

        self::assertSame(['000000000000000000000001', 'a', 'c'], $this->socket->rooms);
    }

    public function testLeavesEveryRoomWhenItDisconnects(): void
    {
        $this->socket->join('a');

        $this->socket->disconnect();
        $this->socket->join('b');

        self::assertSame([], $this->socket->rooms);
        self::assertSame([], $this->io->sockets->adapter()->socketRooms($this->socket->id));
    }

    // Disconnection

    public function testDisconnectLeavesTheNamespaceButKeepsTheConnection(): void
    {
        $events = self::log();
        $this->socket->on('disconnecting', function (string $reason) use ($events): void {
            $events->append(['disconnecting', $reason, $this->socket->rooms]);
        });
        $this->socket->on('disconnect', function (string $reason) use ($events): void {
            $events->append(['disconnect', $reason, $this->socket->rooms]);
        });

        $this->socket->disconnect();
        $this->socket->disconnect();

        self::assertSame(['1'], $this->client->received());
        self::assertFalse($this->client->isClosed());
        self::assertFalse($this->socket->connected);
        self::assertSame([], $this->io->sockets->sockets());
        self::assertSame([
            ['disconnecting', 'server namespace disconnect', ['000000000000000000000001']],
            ['disconnect', 'server namespace disconnect', []],
        ], $events->getArrayCopy());
    }

    public function testDisconnectWithCloseClosesTheConnectionForEveryNamespace(): void
    {
        $this->io->of('/admin');
        $this->client->send('0/admin,');
        $admin = array_values($this->io->of('/admin')->sockets())[0];
        $this->client->received();

        $this->socket->disconnect(true);

        self::assertSame(['1', '1/admin,'], $this->client->received());
        self::assertTrue($this->client->isClosed());
        self::assertFalse($admin->connected);
    }

    public function testTheClientCanDisconnectFromANamespace(): void
    {
        $reasons = $this->reasons();

        $this->client->send('1');

        self::assertSame(['client namespace disconnect'], $reasons->getArrayCopy());
        self::assertFalse($this->client->isClosed());
    }

    public function testCanConnectAgainAfterLeavingANamespace(): void
    {
        $this->client->send('1');
        $this->socket->disconnect();

        $this->client->send('0');

        self::assertSame(['0{"sid":"000000000000000000000002"}'], $this->client->received());
        self::assertFalse($this->client->isClosed());
    }

    public function testCanConnectAgainAfterTheServerDisconnectedIt(): void
    {
        $this->socket->disconnect();
        $this->client->received();

        $this->client->send('0');

        self::assertSame(['0{"sid":"000000000000000000000002"}'], $this->client->received());
    }

    public function testAClosedTransportDisconnectsTheSocket(): void
    {
        $reasons = $this->reasons();

        $this->client->connection->close();

        self::assertSame(['transport close'], $reasons->getArrayCopy());
    }

    public function testAPingTimeoutDisconnectsTheSocket(): void
    {
        $reasons = $this->reasons();

        $this->scheduler->advance(200_000);

        self::assertSame(['ping timeout'], $reasons->getArrayCopy());
    }

    public function testNothingIsSentAfterTheConnectionClosed(): void
    {
        $this->client->connection->close();
        $this->client->received();

        $this->socket->emit('late');

        self::assertSame([], $this->client->received());
    }

    // Helpers

    /**
     * @return array{SocketIOClient, Socket}
     */
    private function connect(): array
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();
        $sockets = $this->io->sockets->sockets();

        return [$client, $sockets[array_key_last($sockets)]];
    }

    /**
     * A socket on polling, and a Closure that polls once into $this->polled.
     *
     * @return array{Socket, Closure(): void}
     */
    private function pollingSocket(): array
    {
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling'));
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('POST', self::POLL_SID2, '40'));
        $poll = function (): void {
            $get = FakeHttpExchange::fromUrl('GET', self::POLL_SID2);
            $this->io->engine->handleRequest($get);
            foreach (explode("\x1e", (string) $get->responseBody) as $packet) {
                if (str_starts_with($packet, '42')) {
                    $this->polled[] = $packet;
                }
            }
        };
        // Takes the CONNECT answer, so the transport has no pending GET.
        $poll();
        $sockets = $this->io->sockets->sockets();

        return [$sockets[array_key_last($sockets)], $poll];
    }

    /**
     * A list that closures can append to.
     *
     * @return ArrayObject<int, mixed>
     */
    private static function log(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @return ArrayObject<int, mixed>
     */
    private function reasons(): ArrayObject
    {
        $reasons = self::log();
        $this->socket->on('disconnect', function (string $reason) use ($reasons): void {
            $reasons->append($reason);
        });

        return $reasons;
    }
}
