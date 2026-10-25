<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Binary;
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

/**
 * Two servers sharing a fake channel broker stand for two processes.
 */
final class ChannelAdapterTest extends TestCase
{
    private FakeChannelBroker $broker;

    /** @var array<string, SocketIO> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->broker = new FakeChannelBroker();
        $this->processes['A'] = $this->process('A', 0x100);
        $this->processes['B'] = $this->process('B', 0x200);
    }

    public function testAnEmitReachesEveryProcessOnce(): void
    {
        [$a1] = $this->connect('A');
        [$b1] = $this->connect('B');
        [$b2] = $this->connect('B');

        $this->processes['A']->emit('news', 1);

        foreach ([$a1, $b1, $b2] as $client) {
            self::assertSame(['2["news",1]'], $client->received());
        }
        self::assertSame(['socket.io#/#'], array_column($this->broker->published, 0));
    }

    public function testARoomBroadcastGoesToTheRoomChannel(): void
    {
        [$a1] = $this->connect('A');
        [$b1, $bSocket] = $this->connect('B');
        $bSocket->join('lobby');

        $this->processes['A']->to('lobby')->emit('hi');

        self::assertSame([], $a1->received());
        self::assertSame(['2["hi"]'], $b1->received());
        self::assertSame(['socket.io#/#lobby#'], array_column($this->broker->published, 0));
        self::assertSame(['B'], $this->broker->subscribers('socket.io#/#lobby#'));
    }

    public function testSeveralRoomsReachASocketInManyOfThemOnce(): void
    {
        [$b1, $bSocket] = $this->connect('B');
        $bSocket->join(['r1', 'r2']);

        $this->processes['A']->to(['r1', 'r2'])->emit('once');

        self::assertSame(['2["once"]'], $b1->received());
        self::assertSame(['socket.io#/#'], array_column($this->broker->published, 0));
    }

    public function testExceptAppliesInEveryProcess(): void
    {
        [$a1, $aSocket] = $this->connect('A');
        [$b1, $bSocket] = $this->connect('B');
        [$b2] = $this->connect('B');
        $aSocket->join('quiet');
        $bSocket->join('quiet');

        $this->processes['A']->except('quiet')->emit('x');

        self::assertSame([], $a1->received());
        self::assertSame([], $b1->received());
        self::assertSame(['2["x"]'], $b2->received());
    }

    public function testASocketRoomGoesThroughTheNamespaceChannel(): void
    {
        [$b1, $bSocket] = $this->connect('B');
        [$b2] = $this->connect('B');

        $this->processes['A']->to($bSocket->id)->emit('private');

        self::assertSame(['2["private"]'], $b1->received());
        self::assertSame([], $b2->received());
        self::assertSame(['socket.io#/#'], array_column($this->broker->published, 0));
    }

    public function testBroadcastFromASocketLeavesOnlyItOut(): void
    {
        [$a1, $aSocket] = $this->connect('A');
        [$a2] = $this->connect('A');
        [$b1] = $this->connect('B');

        $aSocket->broadcast->emit('joined');

        self::assertSame([], $a1->received());
        self::assertSame(['2["joined"]'], $a2->received());
        self::assertSame(['2["joined"]'], $b1->received());
    }

    public function testBinaryCrossesProcesses(): void
    {
        [$b1] = $this->connect('B');

        $this->processes['A']->emit('file', ['data' => new Binary("\x00\xff")]);

        self::assertEquals(
            ['51-["file",{"data":{"_placeholder":true,"num":0}}]', new Binary("\x00\xff")],
            $b1->received(),
        );
    }

    public function testVolatileCrossesProcesses(): void
    {
        $this->processes['A']->volatile->emit('maybe');

        self::assertTrue($this->broker->published[0][1]['volatile'] ?? null);
    }

    public function testNamespacesHaveTheirOwnChannels(): void
    {
        $this->processes['B']->of('/admin');
        [$b1] = $this->connect('B');
        $b1->send('0/admin,');
        $b1->received();

        $this->processes['A']->of('/admin')->emit('alert');
        $this->processes['A']->emit('main');

        self::assertSame(['2/admin,["alert"]', '2["main"]'], $b1->received());
        self::assertSame(['socket.io#/admin#', 'socket.io#/#'], array_column($this->broker->published, 0));
    }

    public function testSubscribesToARoomWhileThisProcessHasMembers(): void
    {
        [, $first] = $this->connect('A');
        [, $second] = $this->connect('A');
        $channel = 'socket.io#/#lobby#';

        $first->join('lobby');
        $second->join('lobby');
        self::assertSame(['A'], $this->broker->subscribers($channel));

        $first->leave('lobby');
        self::assertSame(['A'], $this->broker->subscribers($channel));

        $second->disconnect();
        self::assertSame([], $this->broker->subscribers($channel));
    }

    public function testCloseStopsListening(): void
    {
        [, $socket] = $this->connect('A');
        $socket->join('lobby');
        self::assertSame(['socket.io#/#', 'socket.io#/#lobby#'], $this->broker->channelsOf('A'));

        $this->processes['A']->close();

        self::assertSame([], $this->broker->channelsOf('A'));
    }

    public function testSocketRoomsGetNoChannel(): void
    {
        $this->connect('A');

        self::assertSame(['socket.io#/#'], $this->broker->channelsOf('A'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedMessages(): iterable
    {
        $valid = ['sender' => 'x', 'parts' => [['text', '2["a"]']], 'rooms' => [], 'except' => [], 'volatile' => false];

        yield 'no fields' => [['raw' => 'text']];
        yield 'only a sender' => [['sender' => 'x']];
        yield 'volatile not a bool' => [['volatile' => 'no'] + $valid];
        yield 'unknown part kind' => [['parts' => [['image', 'a']]] + $valid];
        yield 'no parts' => [['parts' => []] + $valid];
        yield 'part not a list' => [['parts' => ['text']] + $valid];
        yield 'room not a string' => [['rooms' => [1]] + $valid];
        yield 'except not a list' => [['except' => 'quiet'] + $valid];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('malformedMessages')]
    public function testIgnoresMalformedMessages(array $message): void
    {
        [$a1] = $this->connect('A');

        $this->broker->publish('socket.io#/#', $message);

        self::assertSame([], $a1->received());
    }

    public function testTheValidFormOfTheMalformedMessagesIsDelivered(): void
    {
        [$a1] = $this->connect('A');

        $this->broker->publish('socket.io#/#', [
            'sender' => 'x',
            'parts' => [['text', '2["a"]']],
            'rooms' => [],
            'except' => [],
            'volatile' => false,
        ]);

        self::assertSame(['2["a"]'], $a1->received());
    }

    public function testTheFactoryNeedsTheEventLoop(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workerStart');

        ChannelAdapter::factory();
    }

    // Helpers

    private function process(string $name, int $firstSocketId): SocketIO
    {
        $io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($firstSocketId),
        );
        $pubSub = $this->broker->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $pubSub));

        return $io;
    }

    /**
     * @return array{SocketIOClient, Socket}
     */
    private function connect(string $process): array
    {
        $io = $this->processes[$process];
        $client = new SocketIOClient($io);
        $client->send('0');
        $client->received();
        $sockets = $io->sockets->sockets();

        return [$client, $sockets[array_key_last($sockets)]];
    }
}
