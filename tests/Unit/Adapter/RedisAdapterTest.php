<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * RedisAdapter shares its broadcasting with ChannelAdapter (see ChannelAdapterTest); these cover what differs.
 */
final class RedisAdapterTest extends TestCase
{
    public function testTheKeyStartsEveryChannel(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A', 'chat-app');
        $b = $this->process($broker, 'B', 'chat-app');
        $client = new SocketIOClient($b);
        $client->send('0');
        $client->received();
        $b->sockets->sockets()[array_key_first($b->sockets->sockets())]->join('lobby');

        $a->to('lobby')->emit('hi');

        self::assertSame(['2["hi"]'], $client->received());
        self::assertSame(['chat-app#/#lobby#'], array_column($broker->published, 0));
        self::assertSame(['chat-app#/#', 'chat-app#/#lobby#'], $broker->channelsOf('B'));
    }

    public function testAppsWithAnotherKeyDoNotHearEachOther(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->process($broker, 'A', 'app-one');
        $b = $this->process($broker, 'B', 'app-two');
        $client = new SocketIOClient($b);
        $client->send('0');
        $client->received();

        $a->emit('news');

        self::assertSame([], $client->received());
    }

    public function testRefusesABroadcastWithAcknowledgementByName(): void
    {
        $io = $this->process(new FakeChannelBroker(), 'A', 'socket.io');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RedisAdapter doesn\'t support broadcasting with an acknowledgement yet');

        $io->timeout(1000)->emit('ask', static function (): void {
        });
    }

    public function testTheFactoryChecksTheUrlFirst(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RedisAdapter::factory('http://127.0.0.1:6379');
    }

    public function testTheFactoryNeedsTheEventLoop(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workerStart');

        RedisAdapter::factory();
    }

    private function process(FakeChannelBroker $broker, string $name, string $key): SocketIO
    {
        $io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($name === 'A' ? 0x100 : 0x200),
        );
        $pubSub = $broker->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new RedisAdapter($nsp, $pubSub, $key));

        return $io;
    }
}
