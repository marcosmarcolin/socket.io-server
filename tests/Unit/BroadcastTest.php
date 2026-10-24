<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

final class BroadcastTest extends TestCase
{
    private SocketIO $io;

    /** @var list<SocketIOClient> */
    private array $clients = [];

    /** @var list<Socket> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            new FakeScheduler(),
            new SequentialSessionIdGenerator(),
            new SequentialSocketIdGenerator(),
        );
        // Four sockets: 0 and 1 in room "a", 1 and 2 in room "b", 3 in no room.
        for ($i = 0; $i < 4; $i++) {
            $this->connect();
        }
        $this->sockets[0]->join('a');
        $this->sockets[1]->join(['a', 'b']);
        $this->sockets[2]->join('b');
    }

    public function testTheServerEmitsToEveryoneInTheMainNamespace(): void
    {
        $this->io->emit('news', ['a' => 1]);

        self::assertSame([1, 1, 1, 1], $this->counts('2["news",{"a":1}]'));
    }

    public function testSendIsAMessageEvent(): void
    {
        $this->io->send('hi');

        self::assertSame([1, 1, 1, 1], $this->counts('2["message","hi"]'));
    }

    public function testToTargetsTheRoomsOnce(): void
    {
        $this->io->to('a')->to('b')->emit('x');

        self::assertSame([1, 1, 1, 0], $this->counts('2["x"]'));
    }

    public function testInIsTo(): void
    {
        $this->io->in(['b'])->emit('x');

        self::assertSame([0, 1, 1, 0], $this->counts('2["x"]'));
    }

    public function testExceptLeavesRoomsOut(): void
    {
        $this->io->except('b')->emit('x');
        $this->io->to('a')->except('b')->emit('y');

        self::assertSame([1, 0, 0, 1], $this->counts('2["x"]', false));
        self::assertSame([1, 0, 0, 0], $this->counts('2["y"]'));
    }

    public function testAnEmptyRoomReachesNobody(): void
    {
        $this->io->to('nobody-here')->emit('x');

        self::assertSame([0, 0, 0, 0], $this->counts('2["x"]'));
    }

    public function testOperatorsAreImmutable(): void
    {
        $toA = $this->io->to('a');

        $toA->to('b');
        $toA->except('a');
        $toA->emit('x');

        self::assertSame([1, 1, 0, 0], $this->counts('2["x"]'));
        self::assertSame($toA, $toA->compress(false));
    }

    public function testBroadcastFromASocketLeavesItOut(): void
    {
        $this->sockets[0]->broadcast->emit('x');
        $this->sockets[1]->to('a')->emit('y');
        $this->sockets[1]->except('b')->emit('z');

        self::assertSame([0, 1, 1, 1], $this->counts('2["x"]', false));
        self::assertSame([1, 0, 0, 0], $this->counts('2["y"]', false));
        self::assertSame([1, 0, 0, 1], $this->counts('2["z"]'));
    }

    public function testASocketCanReachItsOwnRoomOnlyThroughOthers(): void
    {
        $this->io->to($this->sockets[2]->id)->emit('private');

        self::assertSame([0, 0, 1, 0], $this->counts('2["private"]'));
    }

    public function testNamespacesAreSeparate(): void
    {
        $this->io->of('/admin');
        $this->clients[3]->send('0/admin,');
        $this->clients[3]->received();

        $this->io->of('/admin')->emit('alert');
        $this->io->emit('main');

        self::assertSame(['2/admin,["alert"]', '2["main"]'], $this->clients[3]->received());
        self::assertSame([0, 0, 0], array_map(
            static fn (SocketIOClient $client): int => count(array_filter(
                $client->received(),
                static fn (mixed $message): bool => is_string($message) && str_contains($message, 'alert'),
            )),
            array_slice($this->clients, 0, 3),
        ));
    }

    public function testBinaryIsEncodedWithItsAttachments(): void
    {
        $this->io->to('b')->emit('file', new Binary("\x01\x02"));

        $expected = ['51-["file",{"_placeholder":true,"num":0}]', new Binary("\x01\x02")];
        self::assertEquals([], $this->clients[0]->received());
        self::assertEquals($expected, $this->clients[1]->received());
        self::assertEquals($expected, $this->clients[2]->received());
    }

    public function testRefusesAcknowledgements(): void
    {
        $this->expectException(LogicException::class);

        $this->io->emit('ask', static function (): void {
        });
    }

    public function testRefusesReservedNames(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->io->to('a')->emit('disconnect');
    }

    public function testSocketsStillInTheirMiddlewaresAreLeftOut(): void
    {
        $pending = null;
        $this->io->use(static function (Socket $socket, Closure $next) use (&$pending): void {
            $pending = $next;
        });
        $waiting = new SocketIOClient($this->io);
        $waiting->send('0');

        $this->io->emit('x');

        self::assertSame([], $waiting->received());
        self::assertInstanceOf(Closure::class, $pending);
    }

    public function testVolatileBroadcastsSkipBusyTransports(): void
    {
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling'));
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('POST', self::poll('sid5'), '40'));
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', self::poll('sid5')));

        $this->io->volatile->emit('dropped');
        $this->io->sockets->volatile->emit('dropped too');
        $this->io->emit('kept');
        $poll = FakeHttpExchange::fromUrl('GET', self::poll('sid5'));
        $this->io->engine->handleRequest($poll);

        self::assertSame('42["kept"]', $poll->responseBody);
        self::assertSame([1, 1, 1, 1], $this->counts('2["dropped"]'));
    }

    // Socket selection

    public function testAllSocketsListsTheIdsInTheRooms(): void
    {
        self::assertSame($this->ids(0, 1, 2, 3), $this->io->sockets->allSockets());
        self::assertSame($this->ids(0, 1, 2), $this->io->to(['a', 'b'])->allSockets());
        self::assertSame($this->ids(0, 1), $this->io->to('a')->except('b')->allSockets());
    }

    public function testFetchSocketsAppliesTheExceptions(): void
    {
        self::assertSame([$this->sockets[0]], $this->io->to('a')->except('b')->fetchSockets());
        self::assertSame($this->sockets, $this->io->sockets->fetchSockets());
    }

    public function testSocketsJoinAndLeave(): void
    {
        $this->io->to('a')->socketsJoin(['c', 'd']);
        $this->io->sockets->socketsJoin('all');
        $this->io->to('c')->socketsLeave('d');

        self::assertSame(['000000000000000000000001', 'a', 'c', 'all'], $this->sockets[0]->rooms);
        self::assertSame(['000000000000000000000004', 'all'], $this->sockets[3]->rooms);

        $this->io->sockets->socketsLeave(['all', 'c']);
        self::assertSame(['000000000000000000000001', 'a'], $this->sockets[0]->rooms);
    }

    public function testDisconnectSockets(): void
    {
        $this->io->to('b')->disconnectSockets();

        self::assertSame([true, false, false, true], array_map(
            static fn (Socket $socket): bool => $socket->connected,
            $this->sockets,
        ));
        self::assertFalse($this->clients[1]->isClosed());
        self::assertSame($this->ids(0, 3), $this->io->sockets->allSockets());
    }

    public function testDisconnectSocketsCanCloseTheConnections(): void
    {
        $this->io->sockets->except('a')->disconnectSockets(true);

        self::assertSame([false, false, true, true], array_map(
            static fn (SocketIOClient $client): bool => $client->isClosed(),
            $this->clients,
        ));
    }

    public function testUnknownPropertiesThrow(): void
    {
        $this->expectException(LogicException::class);

        $this->io->to('a')->__get('nope');
    }

    // Helpers

    private function connect(): void
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();
        $sockets = $this->io->sockets->sockets();
        $this->clients[] = $client;
        $this->sockets[] = $sockets[array_key_last($sockets)];
    }

    /**
     * How many times each client received the message.
     *
     * @param bool $clear whether to discard what the clients received
     * @return list<int>
     */
    private function counts(string $message, bool $clear = true): array
    {
        $counts = [];
        foreach ($this->clients as $client) {
            $received = array_map(
                static fn (array $frame): string => $frame['binary'] ? '' : substr($frame['data'], 1),
                $client->connection->sent,
            );
            $counts[] = count(array_keys($received, $message, true));
            if ($clear) {
                $client->received();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function ids(int ...$indexes): array
    {
        return array_values(array_map(fn (int $index): string => $this->sockets[$index]->id, $indexes));
    }

    private static function poll(string $sid): string
    {
        return '/socket.io/?EIO=4&transport=polling&sid=' . $sid;
    }
}
