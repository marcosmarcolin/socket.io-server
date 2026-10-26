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
use ReflectionClass;
use Workerman\Events\Swoole;
use Workerman\Worker;

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
        $broadcasts = array_filter(
            $broker->published,
            static fn (array $published): bool => ($published[1]['type'] ?? null) === 3,
        );
        self::assertSame(['chat-app#/#lobby#'], array_column(array_values($broadcasts), 0));
        self::assertSame(['chat-app#/#', 'chat-app-response#/#'], array_map(
            static fn (string $channel): string => preg_replace('/#[0-9a-f]{16}#$/', '#', $channel) ?? $channel,
            array_slice($broker->channelsOf('B'), 0, 2),
        ));
        self::assertContains('chat-app#/#lobby#', $broker->channelsOf('B'));
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

    public function testTheFactoryChecksTheUrlFirst(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RedisAdapter::factory('http://127.0.0.1:6379');
    }

    public function testTheFactoryRefusesTlsOptionsWithoutRediss(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('TLS options need a rediss:// URL'));

        RedisAdapter::factory('redis://127.0.0.1:6379', tls: ['cafile' => '/certs/ca.pem']);
    }

    public function testTheFactoryRefusesTlsOnSwoolesLoop(): void
    {
        // The loop object only, without its constructor, which needs the swoole extension.
        Worker::$globalEvent = (new ReflectionClass(Swoole::class))->newInstanceWithoutConstructor();
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('rediss:// on Swoole');

            RedisAdapter::factory('rediss://127.0.0.1:6379');
        } finally {
            Worker::$globalEvent = null;
        }
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
