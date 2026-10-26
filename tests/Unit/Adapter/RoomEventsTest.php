<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * The adapter's room events, as the reference's: create-room, join-room, leave-room and delete-room.
 */
final class RoomEventsTest extends TestCase
{
    private SocketIO $io;

    /** @var list<list<string>> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        foreach (['create-room', 'join-room', 'leave-room', 'delete-room'] as $event) {
            $this->io->sockets->adapter()->on($event, function (string ...$args) use ($event): void {
                $this->events[] = [$event, ...array_values($args)];
            });
        }
    }

    public function testASocketsOwnRoomIsCreatedWhenItConnects(): void
    {
        $socket = $this->connect();

        self::assertSame([['create-room', $socket->id], ['join-room', $socket->id, $socket->id]], $this->events);
    }

    public function testCreateComesBeforeJoinAndOnlyForTheFirstMember(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $this->events = [];

        $a->join('lobby');
        $b->join(['lobby', 'game']);
        $b->join('lobby');

        self::assertSame([
            ['create-room', 'lobby'],
            ['join-room', 'lobby', $a->id],
            ['join-room', 'lobby', $b->id],
            ['create-room', 'game'],
            ['join-room', 'game', $b->id],
        ], $this->events);
    }

    public function testLeaveComesBeforeDeleteAndOnlyForTheLastMember(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $a->join('lobby');
        $b->join('lobby');
        $this->events = [];

        $a->leave('lobby');
        $a->leave('lobby');
        $b->leave('lobby');

        self::assertSame([
            ['leave-room', 'lobby', $a->id],
            ['leave-room', 'lobby', $b->id],
            ['delete-room', 'lobby'],
        ], $this->events);
    }

    public function testADisconnectLeavesEveryRoom(): void
    {
        $socket = $this->connect();
        $socket->join('lobby');
        $this->events = [];

        $socket->disconnect();

        self::assertSame([
            ['leave-room', $socket->id, $socket->id],
            ['delete-room', $socket->id],
            ['leave-room', 'lobby', $socket->id],
            ['delete-room', 'lobby'],
        ], $this->events);
    }

    public function testTheSocketHelpersFireThemToo(): void
    {
        $socket = $this->connect();
        $this->events = [];

        $this->io->socketsJoin('all');
        $this->io->socketsLeave('all');

        self::assertSame([
            ['create-room', 'all'],
            ['join-room', 'all', $socket->id],
            ['leave-room', 'all', $socket->id],
            ['delete-room', 'all'],
        ], $this->events);
    }

    public function testEachNamespaceHasItsOwnAdapter(): void
    {
        $admin = [];
        $this->io->of('/admin')->adapter()->on('join-room', static function (string $room) use (&$admin): void {
            $admin[] = $room;
        });
        $socket = $this->connect();
        $this->events = [];

        $socket->join('main-only');

        self::assertSame([], $admin);
        self::assertCount(2, $this->events);
    }

    private function connect(): Socket
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();
        $sockets = $this->io->sockets->sockets();

        return $sockets[array_key_last($sockets)];
    }
}
