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
        self::assertSame(['socket.io#/#'], $this->broadcastChannels());
    }

    public function testARoomBroadcastGoesToTheRoomChannel(): void
    {
        [$a1] = $this->connect('A');
        [$b1, $bSocket] = $this->connect('B');
        $bSocket->join('lobby');

        $this->processes['A']->to('lobby')->emit('hi');

        self::assertSame([], $a1->received());
        self::assertSame(['2["hi"]'], $b1->received());
        self::assertSame(['socket.io#/#lobby#'], $this->broadcastChannels());
        self::assertSame(['B'], $this->broker->subscribers('socket.io#/#lobby#'));
    }

    public function testSeveralRoomsReachASocketInManyOfThemOnce(): void
    {
        [$b1, $bSocket] = $this->connect('B');
        $bSocket->join(['r1', 'r2']);

        $this->processes['A']->to(['r1', 'r2'])->emit('once');

        self::assertSame(['2["once"]'], $b1->received());
        self::assertSame(['socket.io#/#'], $this->broadcastChannels());
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
        self::assertSame(['socket.io#/#'], $this->broadcastChannels());
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

        $opts = $this->broadcasts()[0]['opts'] ?? null;
        self::assertIsArray($opts);
        self::assertTrue($opts['volatile'] ?? null);
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
        self::assertSame(['socket.io#/admin#', 'socket.io#/#'], $this->broadcastChannels());
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
        self::assertSame(['socket.io#/#', 'socket.io#/#lobby#'], $this->channelsOf('A'));

        $this->processes['A']->close();

        self::assertSame([], $this->channelsOf('A'));
    }

    public function testSocketRoomsGetNoChannel(): void
    {
        $this->connect('A');

        self::assertSame(['socket.io#/#'], $this->channelsOf('A'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedMessages(): iterable
    {
        $opts = ['rooms' => [], 'except' => [], 'volatile' => false];
        $valid = ['uid' => 'x', 'type' => 3, 'opts' => $opts, 'parts' => [['text', '2["a"]']]];

        yield 'no fields' => [['raw' => 'text']];
        yield 'only a uid' => [['uid' => 'x']];
        yield 'no uid' => [['uid' => null] + $valid];
        yield 'unknown type' => [['type' => 99] + $valid];
        yield 'type not an int' => [['type' => '3'] + $valid];
        yield 'volatile not a bool' => [['opts' => ['volatile' => 'no'] + $opts] + $valid];
        yield 'unknown part kind' => [['parts' => [['image', 'a']]] + $valid];
        yield 'no parts' => [['parts' => []] + $valid];
        yield 'part not a list' => [['parts' => ['text']] + $valid];
        yield 'room not a string' => [['opts' => ['rooms' => [1]] + $opts] + $valid];
        yield 'except not a list' => [['opts' => ['except' => 'quiet'] + $opts] + $valid];
        yield 'opts not an array' => [['opts' => 'all'] + $valid];
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
            'uid' => 'x',
            'type' => 3,
            'opts' => ['rooms' => [], 'except' => [], 'volatile' => false],
            'parts' => [['text', '2["a"]']],
        ]);

        self::assertSame(['2["a"]'], $a1->received());
    }

    public function testOutgoingListenersSeeBroadcastsFromOtherProcesses(): void
    {
        [, $a1] = $this->connect('A');
        [, $b1] = $this->connect('B');
        $seen = [];
        foreach (['A' => $a1, 'B' => $b1] as $process => $socket) {
            $socket->onAnyOutgoing(static function (mixed ...$args) use (&$seen, $process): void {
                $seen[] = [$process, ...$args];
            });
        }

        $this->processes['A']->emit('file', ['data' => new Binary("\x00\xff")], 1);

        self::assertEquals([
            ['A', 'file', ['data' => new Binary("\x00\xff")], 1],
            ['B', 'file', ['data' => new Binary("\x00\xff")], 1],
        ], $seen);
    }

    /**
     * @return iterable<string, array{list<array{string, string}>}>
     */
    public static function partsThatAreNotAnEvent(): iterable
    {
        yield 'an acknowledgement' => [[['text', '30["a"]']]];
        yield 'an invalid packet' => [[['text', '3["a"]']]];
        yield 'an attachment never announced' => [[['binary', "\x01"]]];
    }

    /**
     * @param list<array{string, string}> $parts
     */
    #[DataProvider('partsThatAreNotAnEvent')]
    public function testOutgoingListenersIgnoreRemotePartsThatAreNotAnEvent(array $parts): void
    {
        [, $a1] = $this->connect('A');
        $seen = 0;
        $a1->onAnyOutgoing(static function () use (&$seen): void {
            ++$seen;
        });

        $this->broker->publish('socket.io#/#', [
            'uid' => 'x',
            'type' => 3,
            'opts' => ['rooms' => [], 'except' => [], 'volatile' => false],
            'parts' => $parts,
        ]);

        self::assertSame(0, $seen);
    }

    public function testTheFactoryNeedsTheEventLoop(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('workerStart');

        ChannelAdapter::factory();
    }

    // Helpers

    /**
     * @return list<array{string, array<string, mixed>}> the broadcasts published, leaving heartbeats out
     */
    private function published(): array
    {
        return array_values(array_filter(
            $this->broker->published,
            static fn (array $published): bool => ($published[1]['type'] ?? null) === 3,
        ));
    }

    /**
     * @return list<array<string, mixed>> each broadcast message
     */
    private function broadcasts(): array
    {
        return array_column($this->published(), 1);
    }

    /**
     * @return list<string> the channel of each broadcast
     */
    private function broadcastChannels(): array
    {
        return array_column($this->published(), 0);
    }

    /**
     * @return list<string> the namespace and room channels a process listens to, without its response channel
     */
    private function channelsOf(string $process): array
    {
        return array_values(array_filter(
            $this->broker->channelsOf($process),
            static fn (string $channel): bool => ! str_contains($channel, '-response#'),
        ));
    }

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
