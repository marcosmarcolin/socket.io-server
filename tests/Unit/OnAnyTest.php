<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\DataCountingAdapter;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * Catch-all listeners on a socket, as the reference's onAny() and onAnyOutgoing() families.
 */
final class OnAnyTest extends TestCase
{
    private SocketIO $io;

    /** @var list<SocketIOClient> */
    private array $clients = [];

    /** @var list<Socket> */
    private array $sockets = [];

    /** @var list<mixed> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        for ($i = 0; $i < 2; $i++) {
            $this->connect();
        }
    }

    // Incoming

    public function testOnAnyGetsEveryEventBeforeItsListeners(): void
    {
        $socket = $this->sockets[0];
        $socket->on('chat', function (string $text): void {
            $this->log[] = ['chat', $text];
        });
        $socket->onAny($this->recorder('any'));

        $this->clients[0]->send('2["chat","hi"]');
        $this->clients[0]->send('2["other",1,{"a":true}]');

        self::assertSame([
            ['any', 'chat', 'hi'],
            ['chat', 'hi'],
            ['any', 'other', 1, ['a' => true]],
        ], $this->log);
    }

    public function testOnAnyGetsTheAcknowledgementLast(): void
    {
        $this->sockets[0]->onAny(static function (string $event, mixed ...$args): void {
            $ack = array_pop($args);
            self::assertInstanceOf(Closure::class, $ack);
            $ack('got ' . $event);
        });

        $this->clients[0]->send('25["ask","x"]');

        self::assertSame(['35["got ask"]'], $this->clients[0]->received());
    }

    public function testANumericEventNameArrivesAsAString(): void
    {
        $this->sockets[0]->onAny($this->recorder('any'));

        $this->clients[0]->send('2[42,"x"]');

        self::assertSame([['any', '42', 'x']], $this->log);
    }

    public function testPrependAnyRunsFirst(): void
    {
        $this->sockets[0]->onAny($this->recorder('first'))->prependAny($this->recorder('prepended'));

        $this->clients[0]->send('2["e"]');

        self::assertSame([['prepended', 'e'], ['first', 'e']], $this->log);
    }

    public function testOffAnyRemovesOneRegistrationOrAll(): void
    {
        $socket = $this->sockets[0];
        $twice = $this->recorder('twice');
        $other = $this->recorder('other');
        $socket->onAny($twice)->onAny($other)->onAny($twice);

        $socket->offAny($twice);
        self::assertSame([$other, $twice], $socket->listenersAny());
        $this->clients[0]->send('2["e"]');
        $socket->offAny($this->recorder('never added'));
        self::assertCount(2, $socket->listenersAny());
        $socket->offAny();
        $this->clients[0]->send('2["e"]');

        self::assertSame([['other', 'e'], ['twice', 'e']], $this->log);
        self::assertSame([], $socket->listenersAny());
    }

    public function testAListenerAddedDuringAnEventRunsFromTheNextOne(): void
    {
        $socket = $this->sockets[0];
        $socket->onAny(function (string $event) use ($socket): void {
            $this->log[] = ['outer', $event];
            $socket->onAny($this->recorder('inner'));
        });

        $this->clients[0]->send('2["one"]');

        self::assertSame([['outer', 'one']], $this->log);
    }

    public function testDisconnectingInOnAnySkipsTheEventListeners(): void
    {
        $socket = $this->sockets[0];
        $socket->onAny(static function () use ($socket): void {
            $socket->disconnect();
        });
        $socket->on('e', $this->recorder('listener'));

        $this->clients[0]->send('2["e"]');

        self::assertSame([], $this->log);
        self::assertFalse($socket->connected);
    }

    // Outgoing

    public function testOnAnyOutgoingGetsEmitsBeforeTheyAreWritten(): void
    {
        $client = $this->clients[0];
        $this->sockets[0]->onAnyOutgoing(function (string $event, mixed ...$args) use ($client): void {
            $this->log[] = [$event, ...$args, 'written so far: ' . count($client->connection->sent)];
        });
        $sentBefore = count($client->connection->sent);

        $this->sockets[0]->emit('hello', 'world', static function (): void {
        });
        $this->sockets[0]->send('note');

        self::assertSame([
            ['hello', 'world', 'written so far: ' . $sentBefore],
            ['message', 'note', 'written so far: ' . ($sentBefore + 1)],
        ], $this->log);
    }

    public function testOnAnyOutgoingGetsBroadcastsToItsSocketOnly(): void
    {
        $this->sockets[0]->onAnyOutgoing($this->recorder('0'));
        $this->sockets[1]->onAnyOutgoing($this->recorder('1'));
        $this->sockets[0]->join('room');

        $this->io->emit('all', new Binary("\x01"));
        $this->io->to('room')->emit('room only');
        $this->sockets[0]->broadcast->emit('others');
        $this->io->timeout(1000)->emit('ask', static function (): void {
        });

        self::assertEquals([
            ['0', 'all', new Binary("\x01")],
            ['1', 'all', new Binary("\x01")],
            ['0', 'room only'],
            ['1', 'others'],
            ['0', 'ask'],
            ['1', 'ask'],
        ], $this->log);
    }

    public function testTheAdapterComputesTheEventDataOnceAndOnlyForListeners(): void
    {
        $adapter = null;
        $this->io->adapter(static function (Nsp $nsp) use (&$adapter): Adapter {
            return $adapter = new DataCountingAdapter($nsp);
        });
        $this->sockets = [];
        for ($i = 0; $i < 3; $i++) {
            $this->connect();
        }
        self::assertInstanceOf(DataCountingAdapter::class, $adapter);

        $adapter->deliverEvent();
        $withoutListeners = $adapter->dataRequests;
        $this->sockets[0]->onAnyOutgoing($this->recorder('0'));
        $this->sockets[2]->onAnyOutgoing($this->recorder('2'));
        $adapter->deliverEvent();

        self::assertSame(0, $withoutListeners);
        self::assertSame(1, $adapter->dataRequests);
        self::assertSame([['0', 'e'], ['2', 'e']], $this->log);
    }

    public function testOutgoingListenersCanBePrependedAndRemoved(): void
    {
        $socket = $this->sockets[0];
        $first = $this->recorder('first');
        $socket->onAnyOutgoing($first)->prependAnyOutgoing($this->recorder('prepended'));

        $socket->emit('a');
        $socket->offAnyOutgoing($first);
        self::assertCount(1, $socket->listenersAnyOutgoing());
        $socket->emit('b');
        $socket->offAnyOutgoing();
        $socket->emit('c');

        self::assertSame([['prepended', 'a'], ['first', 'a'], ['prepended', 'b']], $this->log);
        self::assertSame([], $socket->listenersAnyOutgoing());
    }

    public function testIncomingAndOutgoingListenersAreSeparate(): void
    {
        $socket = $this->sockets[0];
        $socket->onAny($this->recorder('in'));
        $socket->onAnyOutgoing($this->recorder('out'));

        $this->clients[0]->send('2["from client"]');
        $socket->emit('to client');

        self::assertSame([['in', 'from client'], ['out', 'to client']], $this->log);
    }

    // Helpers

    private function recorder(string $name): Closure
    {
        return function (mixed ...$args) use ($name): void {
            $this->log[] = [$name, ...$args];
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
