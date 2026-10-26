<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Emitter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * The Emitter, publishing from outside the servers, as the reference's emitter packages: two servers share a fake
 * broker with it.
 */
final class EmitterTest extends TestCase
{
    private FakeChannelBroker $broker;

    /** @var array<string, SocketIO> */
    private array $servers = [];

    private Emitter $emitter;

    protected function setUp(): void
    {
        $this->broker = new FakeChannelBroker();
        $this->server('A', 0x100);
        $this->server('B', 0x200);
        $this->emitter = new Emitter($this->broker->connect('php-fpm'));
    }

    public function testAnEmitReachesTheClientsOfEveryServer(): void
    {
        [$a] = $this->connect('A');
        [$b] = $this->connect('B');

        $this->emitter->emit('news', ['id' => 1], new Binary("\x00\xff"));

        foreach ([$a, $b] as $client) {
            self::assertEquals(
                ['51-["news",{"id":1},{"_placeholder":true,"num":0}]', new Binary("\x00\xff")],
                $client->received(),
            );
        }
    }

    public function testRoomsAndExceptSelectOnEveryServer(): void
    {
        [$a, $aSocket] = $this->connect('A');
        [$b1, $b1Socket] = $this->connect('B');
        [$b2, $b2Socket] = $this->connect('B');
        $aSocket->join('user:42');
        $b1Socket->join(['user:42', 'muted']);
        $b2Socket->join('user:7');

        $this->emitter->to('user:42')->emit('one-room');
        $this->emitter->to(['user:42', 'user:7'])->except('muted')->emit('two-rooms');
        $this->emitter->in($b2Socket->id)->emit('one-socket');

        self::assertSame(['2["one-room"]', '2["two-rooms"]'], $a->received());
        self::assertSame(['2["one-room"]'], $b1->received());
        self::assertSame(['2["two-rooms"]', '2["one-socket"]'], $b2->received());
    }

    public function testASingleRoomGoesToItsChannel(): void
    {
        $this->emitter->to('user:42')->emit('x');
        $this->emitter->emit('y');

        $channels = array_column(array_slice($this->broker->published, -2), 0);
        self::assertSame(['socket.io#/#user:42#', 'socket.io#/#'], $channels);
    }

    public function testOfTargetsAnotherNamespace(): void
    {
        $this->servers['B']->of('/admin');
        [$b] = $this->connect('B');
        $b->send('0/admin,');
        $b->received();

        $this->emitter->of('/admin')->emit('alert');
        $this->emitter->emit('main');

        self::assertSame(['2/admin,["alert"]', '2["main"]'], $b->received());
    }

    public function testTheKeyMatchesTheServers(): void
    {
        [$a] = $this->connect('A');

        (new Emitter($this->broker->connect('other'), 'another-app'))->emit('lost');

        self::assertSame([], $a->received());
    }

    public function testVolatileIsCarried(): void
    {
        $this->emitter->volatile->emit('maybe');

        $published = $this->broker->published;
        $opts = (end($published) ?: [1 => []])[1]['opts'] ?? null;
        self::assertIsArray($opts);
        self::assertTrue($opts['volatile'] ?? null);
    }

    public function testSocketHelpersApplyOnEveryServer(): void
    {
        [, $a] = $this->connect('A');
        [$bClient, $b] = $this->connect('B');

        $this->emitter->socketsJoin('everyone');
        $this->emitter->in($a->id)->socketsLeave('everyone');
        $this->emitter->in($b->id)->disconnectSockets();

        self::assertSame([$a->id], $a->rooms);
        self::assertFalse($b->connected);
        self::assertSame(['1'], $bClient->received());
    }

    public function testServerSideEmitReachesEveryServer(): void
    {
        $received = [];
        foreach (['A', 'B'] as $name) {
            $this->servers[$name]->on('cache-clear', static function (string $key) use (&$received, $name): void {
                $received[] = [$name, $key];
            });
        }

        $this->emitter->serverSideEmit('cache-clear', 'users');

        self::assertSame([['A', 'users'], ['B', 'users']], $received);
    }

    public function testAnEmitterIsNotCountedAsAServer(): void
    {
        $this->emitter->emit('x');

        self::assertSame(2, $this->servers['A']->sockets->adapter()->serverCount());
    }

    public function testRefusesAcknowledgements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acknowledgements');

        $this->emitter->emit('ask', static function (): void {
        });
    }

    public function testRefusesReservedNames(): void
    {
        $refused = 0;
        foreach (['disconnect', 'connect_error'] as $event) {
            try {
                $this->emitter->emit($event);
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }
        try {
            $this->emitter->serverSideEmit('connection');
        } catch (InvalidArgumentException) {
            ++$refused;
        }

        self::assertSame(3, $refused);
    }

    public function testTheRedisEmitterRefusesTlsOptionsWithoutRediss(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('TLS options need a rediss:// URL'));

        Emitter::redis('redis://127.0.0.1:6379', tls: ['cafile' => '/certs/ca.pem']);
    }

    public function testANamespaceStartsWithASlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->emitter->of('admin');
    }

    public function testOnlyVolatileIsAProperty(): void
    {
        $this->expectException(LogicException::class);

        $this->emitter->__get('broadcast');
    }

    public function testModifiersReturnNewEmitters(): void
    {
        [$a, $aSocket] = $this->connect('A');
        $aSocket->join('r');
        $base = $this->emitter;

        $base->to('nobody')->emit('x');
        $base->emit('to-all');

        self::assertSame(['2["to-all"]'], $a->received());
    }

    private function server(string $name, int $firstSocketId): void
    {
        $io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($firstSocketId),
        );
        $pubSub = $this->broker->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $pubSub));
        $this->servers[$name] = $io;
    }

    /**
     * @return array{SocketIOClient, Socket}
     */
    private function connect(string $name): array
    {
        $io = $this->servers[$name];
        $client = new SocketIOClient($io);
        $client->send('0');
        $client->received();
        $sockets = $io->sockets->sockets();

        return [$client, $sockets[array_key_last($sockets)]];
    }
}
