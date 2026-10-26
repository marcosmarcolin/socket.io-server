<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Adapter\PubSubAdapter;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use Marcolin\SocketIO\TimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The cluster protocol of PubSubAdapter: servers sharing a fake broker, each with its own clock.
 */
final class ClusterTest extends TestCase
{
    private FakeChannelBroker $broker;

    /** @var array<string, SocketIO> */
    private array $servers = [];

    /** @var array<string, FakeScheduler> */
    private array $clocks = [];

    /** @var array{?Throwable, mixed}|null what the last callback got */
    private ?array $result = null;

    protected function setUp(): void
    {
        $this->broker = new FakeChannelBroker();
    }

    public function testServersCountEachOtherAsTheyStart(): void
    {
        $this->server('A');
        self::assertSame(1, $this->servers('A'));

        $this->server('B');
        self::assertSame([2, 2], [$this->servers('A'), $this->servers('B')]);

        $this->server('C');
        self::assertSame([3, 3, 3], [$this->servers('A'), $this->servers('B'), $this->servers('C')]);
    }

    public function testEachNamespaceCountsItsOwnServers(): void
    {
        $this->server('A')->of('/admin');
        $this->server('B');

        self::assertSame([2, 1], [$this->servers('A'), $this->servers('A', '/admin')]);
    }

    public function testAHeartbeatGoesOutAfterAnIntervalWithoutPublishing(): void
    {
        $this->server('A');
        $this->broker->published = [];

        $this->clocks['A']->advance(PubSubAdapter::HEARTBEAT_INTERVAL - 1);
        self::assertSame([], $this->types());
        $this->clocks['A']->advance(1);
        self::assertSame([2], $this->types());
    }

    public function testAnyPublishPutsTheHeartbeatOff(): void
    {
        $this->server('A');
        $this->clocks['A']->advance(4000);
        $this->servers['A']->emit('news');
        $this->broker->published = [];

        $this->clocks['A']->advance(PubSubAdapter::HEARTBEAT_INTERVAL - 1);
        self::assertSame([], $this->types());
        $this->clocks['A']->advance(1);
        self::assertSame([2], $this->types());
    }

    public function testAServerSilentForTheTimeoutIsGone(): void
    {
        $this->server('A');
        $this->server('B');

        // B's clock stands still, so it sends no heartbeat.
        $this->clocks['A']->advance(PubSubAdapter::HEARTBEAT_TIMEOUT - 1);
        self::assertSame(2, $this->servers('A'));
        $this->clocks['A']->advance(1);
        self::assertSame(1, $this->servers('A'));
    }

    public function testHeartbeatsKeepServersCounted(): void
    {
        $this->server('A');
        $this->server('B');

        for ($i = 0; $i < 6; $i++) {
            $this->clocks['A']->advance(PubSubAdapter::HEARTBEAT_INTERVAL);
            $this->clocks['B']->advance(PubSubAdapter::HEARTBEAT_INTERVAL);
        }

        self::assertSame([2, 2], [$this->servers('A'), $this->servers('B')]);
    }

    public function testAClosedServerLeavesTheCountAtOnce(): void
    {
        $this->server('A');
        $this->server('B');

        $this->servers['B']->close();

        self::assertSame(1, $this->servers('A'));
        self::assertSame([], $this->broker->channelsOf('B'));
        // No heartbeat after closing.
        $this->broker->published = [];
        $this->clocks['B']->advance(PubSubAdapter::HEARTBEAT_INTERVAL * 3);
        self::assertSame([], $this->types());
    }

    public function testSocketsJoinAppliesOnEveryServer(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        [, $b] = $this->connect('B');

        $this->servers['A']->sockets->socketsJoin('everyone');
        $this->servers['A']->in($b->id)->socketsJoin(['vip', 'beta']);

        self::assertSame([$a->id, 'everyone'], $a->rooms);
        self::assertSame([$b->id, 'everyone', 'vip', 'beta'], $b->rooms);
    }

    public function testRoomEventsFireWhereTheSocketIs(): void
    {
        $this->server('A');
        $this->server('B');
        $this->connect('A');
        [, $b] = $this->connect('B');
        $seen = [];
        foreach (['A', 'B'] as $name) {
            $this->servers[$name]->sockets->adapter()->on(
                'join-room',
                static function (string $room, string $id) use (&$seen, $name): void {
                    $seen[] = [$name, $room, $id];
                },
            );
        }

        $this->servers['A']->in($b->id)->socketsJoin('vip');

        self::assertSame([['B', 'vip', $b->id]], $seen);
    }

    public function testSocketsJoinOfOneRoomReachesTheServersWithItsMembers(): void
    {
        $this->server('A');
        $this->server('B');
        [, $b1] = $this->connect('B');
        [, $b2] = $this->connect('B');
        $b1->join('lobby');
        $this->broker->published = [];

        $this->servers['A']->in('lobby')->socketsJoin('game');

        self::assertSame([$b1->id, 'lobby', 'game'], $b1->rooms);
        self::assertSame([$b2->id], $b2->rooms);
        self::assertSame('socket.io#/#lobby#', $this->published()[0][0]);
    }

    public function testSocketsLeaveAppliesOnEveryServer(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        [, $b] = $this->connect('B');
        $a->join(['x', 'y']);
        $b->join(['x', 'y']);

        $this->servers['B']->except('y')->socketsLeave('x');
        $this->servers['B']->sockets->socketsLeave('x');

        self::assertSame([$a->id, 'y'], $a->rooms);
        self::assertSame([$b->id, 'y'], $b->rooms);
    }

    public function testDisconnectSocketsAppliesOnEveryServer(): void
    {
        $this->server('A');
        $this->server('B');
        [$aClient, $a] = $this->connect('A');
        [$bClient, $b] = $this->connect('B');
        $reasons = [];
        $b->on('disconnect', static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $this->servers['A']->in($b->id)->disconnectSockets();

        self::assertTrue($a->connected);
        self::assertFalse($b->connected);
        self::assertSame(['server namespace disconnect'], $reasons);
        self::assertSame(['1'], $bClient->received());
        self::assertSame([], $aClient->received());
    }

    public function testDisconnectSocketsCanCloseTheConnections(): void
    {
        $this->server('A');
        $this->server('B');
        [$bClient] = $this->connect('B');

        $this->servers['A']->sockets->disconnectSockets(true);

        self::assertTrue($bClient->isClosed());
    }

    public function testServerSideEmitReachesTheOtherServersOnly(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        $received = [];
        foreach (['A', 'B', 'C'] as $name) {
            $this->servers[$name]->on('hello', static function (mixed ...$args) use (&$received, $name): void {
                $received[] = [$name, ...$args];
            });
        }

        $this->servers['A']->serverSideEmit('hello', 'world', ['n' => 1], new Binary("\x00\xff"));

        self::assertEquals([
            ['B', 'world', ['n' => 1], new Binary("\x00\xff")],
            ['C', 'world', ['n' => 1], new Binary("\x00\xff")],
        ], $received);
    }

    public function testServerSideEmitStaysInItsNamespace(): void
    {
        $this->server('A')->of('/admin');
        $this->server('B')->of('/admin');
        $received = [];
        $this->servers['B']->on('ping', static function () use (&$received): void {
            $received[] = '/';
        });
        $this->servers['B']->of('/admin')->on('ping', static function () use (&$received): void {
            $received[] = '/admin';
        });

        $this->servers['A']->of('/admin')->serverSideEmit('ping');

        self::assertSame(['/admin'], $received);
    }

    public function testServerSideEmitGetsOneResponsePerServer(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        foreach (['B', 'C'] as $name) {
            $this->servers[$name]->on('ping', static function (int $n, Closure $ack) use ($name): void {
                $ack($name . ':' . $n, 'ignored');
                $ack('twice');
            });
        }

        $this->servers['A']->serverSideEmit('ping', 7, $this->recorder());

        self::assertSame([null, ['B:7', 'C:7']], $this->result);
    }

    public function testServerSideEmitWithoutOtherServersAnswersAtOnce(): void
    {
        $this->server('A');

        $this->servers['A']->serverSideEmit('ping', $this->recorder());

        self::assertSame([null, []], $this->result);
    }

    public function testServerSideEmitTimesOutWithTheResponsesSoFar(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        $this->servers['B']->on('ping', static function (Closure $ack): void {
            $ack('pong');
        });
        $this->servers['C']->on('ping', static function (): void {
        });

        $this->servers['A']->serverSideEmit('ping', $this->recorder());
        self::assertNull($this->result);
        $this->clocks['A']->advance(PubSubAdapter::REQUEST_TIMEOUT);

        [$error, $responses] = $this->callbackResult();
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame('timeout reached: missing 1 responses', $error->getMessage());
        self::assertSame(['pong'], $responses);
    }

    public function testAServerLeavingCompletesTheRequest(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        $this->servers['B']->on('ping', static function (Closure $ack): void {
            $ack('pong');
        });
        $this->servers['A']->serverSideEmit('ping', $this->recorder());

        $this->servers['C']->close();

        self::assertSame([null, ['pong']], $this->result);
    }

    public function testALateResponseIsIgnored(): void
    {
        $this->server('A');
        $this->server('B');
        $acks = [];
        $this->servers['B']->on('ping', static function (Closure $ack) use (&$acks): void {
            $acks[] = $ack;
        });
        $calls = 0;
        $this->servers['A']->serverSideEmit('ping', static function () use (&$calls): void {
            ++$calls;
        });
        $this->clocks['A']->advance(PubSubAdapter::REQUEST_TIMEOUT);

        $acks[0]('late');

        self::assertSame(1, $calls);
    }

    public function testFetchSocketsWithoutACallbackReadsThisProcess(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        $this->connect('B');

        self::assertSame([$a], $this->servers['A']->sockets->fetchSockets());
    }

    public function testFetchSocketsWithACallbackGetsEveryServersSockets(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        [, $a] = $this->connect('A');
        [, $b] = $this->connect('B', ['token' => 'secret']);
        $b->join('lobby');
        $b->data = ['user' => 'ana', 'since' => new \DateTimeImmutable('2026-01-02T03:04:05+00:00')];
        $fetched = null;

        $this->servers['A']->sockets->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$fetched): void {
                $fetched = [$error, $sockets];
            },
        );

        self::assertNotNull($fetched);
        [$error, $sockets] = $fetched;
        self::assertNull($error);
        self::assertCount(2, $sockets);
        self::assertSame($a, $sockets[0]);
        $remote = $sockets[1];
        self::assertInstanceOf(RemoteSocket::class, $remote);
        self::assertSame($b->id, $remote->id);
        self::assertSame([$b->id, 'lobby'], $remote->rooms);
        self::assertSame(['token' => 'secret'], $remote->handshake['auth'] ?? null);
        self::assertSame($b->handshake['issued'], $remote->handshake['issued'] ?? null);
        // Objects cross as JSON.
        self::assertSame(['user' => 'ana', 'since' => [
            'date' => '2026-01-02 03:04:05.000000',
            'timezone_type' => 1,
            'timezone' => '+00:00',
        ]], $remote->data);
    }

    public function testFetchSocketsAppliesRoomsAndExceptOnEveryServer(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        [, $b1] = $this->connect('B');
        [, $b2] = $this->connect('B');
        [, $b3] = $this->connect('B');
        $a->join('lobby');
        $b1->join('lobby');
        $b2->join(['lobby', 'muted']);
        $this->broker->published = [];
        $ids = null;

        $this->servers['A']->to('lobby')->except('muted')->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$ids): void {
                $ids = array_map(static fn (Socket|RemoteSocket $socket): string => $socket->id, $sockets);
            },
        );

        // $b3 is in neither room.
        self::assertSame([$a->id, $b1->id], $ids);
        // The request goes to the namespace channel, which every server hears, not the room's.
        self::assertSame('socket.io#/#', $this->published()[0][0]);
    }

    public function testFetchSocketsAloneAnswersAtOnce(): void
    {
        $this->server('A');
        [, $a] = $this->connect('A');
        $fetched = null;

        $this->servers['A']->sockets->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$fetched): void {
                $fetched = [$error, $sockets];
            },
        );

        self::assertSame([null, [$a]], $fetched);
    }

    public function testFetchSocketsTimesOutWithTheSocketsSoFar(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        [, $b] = $this->connect('B');
        // A server that heartbeats but never answers.
        $this->broker->publish('socket.io#/#', ['uid' => 'mute', 'type' => 2]);

        $this->servers['A']->sockets->fetchSockets($this->recorder());
        self::assertNull($this->result);
        $this->clocks['A']->advance(PubSubAdapter::REQUEST_TIMEOUT);

        [$error, $sockets] = $this->callbackResult();
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame('timeout reached: missing 1 responses', $error->getMessage());
        self::assertIsArray($sockets);
        self::assertSame([$a->id, $b->id], array_map(
            static fn (mixed $socket): ?string => $socket instanceof Socket || $socket instanceof RemoteSocket
                ? $socket->id
                : null,
            $sockets,
        ));
    }

    public function testARemoteSocketActsOnTheSocketOnItsServer(): void
    {
        $this->server('A');
        $this->server('B');
        [$bClient, $b] = $this->connect('B');
        $remote = null;
        $this->servers['A']->sockets->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$remote): void {
                $remote = $sockets[0] ?? null;
            },
        );
        self::assertInstanceOf(RemoteSocket::class, $remote);

        $remote->emit('hello', 'from A');
        $remote->join(['x', 'y']);
        $remote->leave('x');
        self::assertSame(['2["hello","from A"]'], $bClient->received());
        self::assertSame([$b->id, 'y'], $b->rooms);

        $remote->disconnect();
        self::assertFalse($b->connected);
        self::assertSame(['1'], $bClient->received());
    }

    public function testARemoteSocketGetsOneAnswerAfterTimeout(): void
    {
        $this->server('A');
        $this->server('B');
        [$bClient] = $this->connect('B');
        $remote = $this->firstRemote();

        $remote->timeout(1000)->emit('ask', 'q', $this->recorder());
        self::assertSame(['20["ask","q"]'], $bClient->received());
        $bClient->send('30["answer"]');

        self::assertSame([null, 'answer'], $this->callbackResult());
    }

    public function testARemoteSocketThatDoesNotAnswerTimesOut(): void
    {
        $this->server('A');
        $this->server('B');
        $this->connect('B');
        $remote = $this->firstRemote();

        $remote->timeout(1000)->emit('ask', $this->recorder());
        $this->clocks['A']->advance(1000);

        [$error, $answer] = $this->callbackResult();
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertNull($answer);
    }

    public function testARemoteSocketNeedsATimeoutForAnAcknowledgement(): void
    {
        $this->server('A');
        $this->server('B');
        $this->connect('B');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('timeout()');

        $this->firstRemote()->emit('ask', static function (): void {
        });
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedSocketLists(): iterable
    {
        $valid = ['id' => 'x', 'handshake' => '{}', 'rooms' => ['x'], 'data' => 'null'];

        yield 'not a list' => [['a' => $valid]];
        yield 'an entry that is not an array' => [['x']];
        yield 'no id' => [[['id' => null] + $valid]];
        yield 'rooms not strings' => [[['rooms' => [1]] + $valid]];
        yield 'handshake not JSON' => [[['handshake' => '{'] + $valid]];
        yield 'handshake not an object' => [[['handshake' => '1'] + $valid]];
        yield 'data not a string' => [[['data' => []] + $valid]];
    }

    #[DataProvider('malformedSocketLists')]
    public function testIgnoresMalformedSocketLists(mixed $sockets): void
    {
        self::assertNull($this->fetchAnswered($sockets));
    }

    public function testTheValidFormOfTheMalformedSocketListsCompletes(): void
    {
        $fetched = $this->fetchAnswered([['id' => 'x', 'handshake' => '{}', 'rooms' => ['x'], 'data' => 'null']]);

        self::assertNotNull($fetched);
        self::assertNull($fetched[0]);
        self::assertSame(['x'], array_map(static fn (Socket|RemoteSocket $s): string => $s->id, $fetched[1]));
    }

    /**
     * Fetches from A while only a mute server is known, which then answers with these sockets.
     *
     * @return array{?Throwable, list<Socket|RemoteSocket>}|null what the callback got, if it ran
     */
    private function fetchAnswered(mixed $sockets): ?array
    {
        $this->server('A');
        $this->server('B');
        $this->servers['B']->close();
        // A server that never answers by itself.
        $this->broker->publish('socket.io#/#', ['uid' => 'mute', 'type' => 2]);
        $fetched = null;
        $this->servers['A']->sockets->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$fetched): void {
                $fetched = [$error, $sockets];
            },
        );
        $published = $this->published();
        $request = end($published)[1] ?? [];
        $channel = $this->broker->channelsOf('A')[1];

        $this->broker->publish($channel, [
            'uid' => 'mute',
            'type' => 8,
            'requestId' => $request['requestId'] ?? '',
            'sockets' => $sockets,
        ]);

        return $fetched;
    }

    public function testABroadcastWithAckGetsTheAnswersOfEveryServer(): void
    {
        $this->server('A');
        $this->server('B');
        $this->server('C');
        [$a] = $this->connect('A');
        [$b1] = $this->connect('B');
        [$b2] = $this->connect('B');
        $this->connect('C');
        $this->servers['C']->close();
        $this->server('D');

        $this->servers['A']->timeout(1000)->emit('ask', 1, $this->recorder());

        // Each server numbers the acknowledgement from its own namespace's counter.
        self::assertSame(['20["ask",1]'], $a->received());
        self::assertSame(['20["ask",1]'], $b1->received());
        self::assertSame(['20["ask",1]'], $b2->received());
        $b2->send('30["b2"]');
        $a->send('30[{"n":"a"}]');
        self::assertNull($this->result);
        $b1->send('30["b1","ignored"]');

        self::assertSame([null, ['b2', ['n' => 'a'], 'b1']], $this->callbackResult());
    }

    public function testABroadcastWithAckToARoomWaitsForEveryServersCount(): void
    {
        $this->server('A');
        $this->server('B');
        [$aClient, $a] = $this->connect('A');
        $a->join('lobby');
        $this->connect('B');
        $this->broker->published = [];

        $this->servers['A']->to('lobby')->timeout(1000)->emit('ask', $this->recorder());
        $aClient->received();
        $aClient->send('30["yes"]');

        // B has no member, but heard the request on the namespace channel and reported zero.
        self::assertSame([null, ['yes']], $this->callbackResult());
        self::assertSame('socket.io#/#', $this->published()[0][0]);
    }

    public function testABroadcastWithAckTimesOutWithTheAnswersSoFar(): void
    {
        $this->server('A');
        $this->server('B');
        [$aClient] = $this->connect('A');
        [$bClient] = $this->connect('B');

        $this->servers['A']->timeout(1000)->emit('ask', $this->recorder());
        $aClient->send('30["a"]');
        $this->clocks['A']->advance(1000);
        $this->clocks['B']->advance(1000);

        [$error, $answers] = $this->callbackResult();
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame(['a'], $answers);

        // B's socket forgot the acknowledgement, so a late answer publishes nothing.
        $this->broker->published = [];
        $bClient->send('30["late"]');
        self::assertSame([], $this->published());
    }

    public function testABroadcastWithAckWaitsForASilentServer(): void
    {
        $this->server('A');
        [$aClient] = $this->connect('A');
        // A server that heartbeats but never reports.
        $this->broker->publish('socket.io#/#', ['uid' => 'mute', 'type' => 2]);

        $this->servers['A']->timeout(1000)->emit('ask', $this->recorder());
        $aClient->send('30["a"]');
        self::assertNull($this->result);
        $this->clocks['A']->advance(1000);

        self::assertInstanceOf(TimeoutException::class, $this->callbackResult()[0]);
    }

    public function testABroadcastWithAckReachesBinaryAnswersAndVolatileSockets(): void
    {
        $this->server('A');
        $this->server('B');
        [$bClient] = $this->connect('B');

        $this->servers['A']->volatile->timeout(1000)->emit('file', new Binary("\x01"), $this->recorder());
        self::assertEquals(['51-0["file",{"_placeholder":true,"num":0}]', new Binary("\x01")], $bClient->received());
        $bClient->send('61-0[{"_placeholder":true,"num":0}]');
        $bClient->sendBinary("\x02");

        self::assertEquals([null, [new Binary("\x02")]], $this->callbackResult());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedAckResponses(): iterable
    {
        yield 'a negative count' => [['type' => 11, 'clientCount' => -1]];
        yield 'a count that is not an int' => [['type' => 11, 'clientCount' => '1']];
        yield 'an answer that is not an ack' => [['type' => 12, 'parts' => [['text', '2["x"]']]]];
        yield 'an answer without parts' => [['type' => 12]];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('malformedAckResponses')]
    public function testIgnoresMalformedAckResponses(array $response): void
    {
        $this->server('A');
        $this->broker->publish('socket.io#/#', ['uid' => 'mute', 'type' => 2]);
        $this->servers['A']->timeout(1000)->emit('ask', $this->recorder());
        $published = $this->published();
        $requestId = end($published)[1]['requestId'] ?? '';
        $channel = $this->broker->channelsOf('A')[1];

        $this->broker->publish($channel, ['uid' => 'mute', 'requestId' => $requestId] + $response);

        self::assertNull($this->result);
        // The valid count completes it: no client anywhere.
        $count = ['uid' => 'mute', 'type' => 11, 'requestId' => $requestId, 'clientCount' => 0];
        $this->broker->publish($channel, $count);
        self::assertSame([null, []], $this->callbackResult());
    }

    public function testALocalBroadcastStaysInThisProcess(): void
    {
        $this->server('A');
        $this->server('B');
        [$a1] = $this->connect('A');
        [$a2, $a2Socket] = $this->connect('A');
        [$b] = $this->connect('B');
        $a2Socket->join('lobby');
        $this->broker->published = [];

        $this->servers['A']->local->emit('news');
        $this->servers['A']->local->to('lobby')->emit('lobby-only');
        $this->servers['A']->to('lobby')->local->volatile->emit('chained');

        self::assertSame(['2["news"]'], $a1->received());
        self::assertSame(['2["news"]', '2["lobby-only"]', '2["chained"]'], $a2->received());
        self::assertSame([], $b->received());
        self::assertSame([], $this->published());
    }

    public function testASocketsLocalBroadcastLeavesItOut(): void
    {
        $this->server('A');
        $this->server('B');
        [$a1, $a1Socket] = $this->connect('A');
        [$a2] = $this->connect('A');
        [$b] = $this->connect('B');

        $a1Socket->local->emit('hi');

        self::assertSame([], $a1->received());
        self::assertSame(['2["hi"]'], $a2->received());
        self::assertSame([], $b->received());
    }

    public function testLocalSocketHelpersStayInThisProcess(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        [, $b] = $this->connect('B');
        $this->broker->published = [];

        $this->servers['A']->local->socketsJoin('here');
        $this->servers['B']->sockets->local->socketsJoin('there');
        $this->servers['A']->local->socketsLeave('here');
        $this->servers['A']->local->disconnectSockets();

        self::assertFalse($a->connected);
        self::assertTrue($b->connected);
        self::assertSame([$b->id, 'there'], $b->rooms);
        self::assertSame([], $this->published());
    }

    public function testALocalFetchWithACallbackAsksNoOtherServer(): void
    {
        $this->server('A');
        $this->server('B');
        [, $a] = $this->connect('A');
        $this->connect('B');
        $this->broker->published = [];

        $this->servers['A']->local->fetchSockets($this->recorder());

        self::assertSame([null, [$a]], $this->callbackResult());
        self::assertSame([], $this->published());
    }

    public function testALocalBroadcastWithAckWaitsForThisProcessOnly(): void
    {
        $this->server('A');
        $this->server('B');
        [$a] = $this->connect('A');
        [$b] = $this->connect('B');
        $this->broker->published = [];

        $this->servers['A']->local->timeout(1000)->emit('ask', $this->recorder());
        $a->send('30["a"]');

        self::assertSame([null, ['a']], $this->callbackResult());
        self::assertSame([], $b->received());
        self::assertSame([], $this->published());
    }

    public function testServerSideEmitRefusesTheNamespacesOwnEvents(): void
    {
        $this->server('A');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"connection" is a reserved event name');

        $this->servers['A']->serverSideEmit('connection');
    }

    public function testAReceivedReservedEventIsIgnored(): void
    {
        $this->server('B');
        $connections = 0;
        $this->servers['B']->on('connection', static function () use (&$connections): void {
            ++$connections;
        });

        $this->broker->publish('socket.io#/#', [
            'uid' => 'x',
            'type' => 9,
            'parts' => [['text', '2["connection"]']],
        ]);

        self::assertSame(0, $connections);
    }

    public function testTheInMemoryAdapterHasNoServersToReach(): void
    {
        $io = new SocketIO(scheduler: new FakeScheduler());

        self::assertSame(1, $io->sockets->adapter()->serverCount());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('serverSideEmit() needs an adapter that reaches other servers');

        $io->serverSideEmit('hello');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedRequests(): iterable
    {
        $opts = ['rooms' => [], 'except' => [], 'volatile' => false];

        yield 'join without rooms' => [['type' => 4, 'opts' => $opts]];
        yield 'join with a room that is not a string' => [['type' => 4, 'opts' => $opts, 'rooms' => [1]]];
        yield 'join without opts' => [['type' => 4, 'rooms' => ['x']]];
        yield 'disconnect without close' => [['type' => 6, 'opts' => $opts]];
        yield 'disconnect with close not a bool' => [['type' => 6, 'opts' => $opts, 'close' => 1]];
        yield 'server-side emit without parts' => [['type' => 9]];
        yield 'server-side emit of a packet that is not an event' => [['type' => 9, 'parts' => [['text', '30[1]']]]];
        yield 'server-side emit of an event without a name' => [['type' => 9, 'parts' => [['text', '2[1]']]]];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('malformedRequests')]
    public function testIgnoresMalformedRequests(array $message): void
    {
        $this->server('B');
        [, $b] = $this->connect('B');
        $calls = 0;
        $this->servers['B']->on('1', static function () use (&$calls): void {
            ++$calls;
        });

        $this->broker->publish('socket.io#/#', ['uid' => 'x'] + $message);

        self::assertSame([$b->id], $b->rooms);
        self::assertTrue($b->connected);
        self::assertSame(0, $calls);
    }

    public function testIgnoresResponsesItDidNotAskFor(): void
    {
        $this->server('A');
        $this->server('B');
        $this->servers['B']->on('ping', static function (): void {
        });
        $this->servers['A']->serverSideEmit('ping', $this->recorder());
        $channel = $this->broker->channelsOf('A')[1];

        // From a server that wasn't asked, then for an unknown request.
        $requestId = $this->published()[array_key_last($this->published())][1]['requestId'] ?? '';
        $response = ['uid' => 'stranger', 'type' => 10, 'parts' => [['text', '30["x"]']]];
        $this->broker->publish($channel, ['requestId' => $requestId] + $response);
        $this->broker->publish($channel, ['requestId' => 'nope'] + $response);

        self::assertNull($this->result);
    }

    // Helpers

    /**
     * The first socket A fetches, which must be on another server.
     */
    private function firstRemote(): RemoteSocket
    {
        $remote = null;
        $this->servers['A']->sockets->fetchSockets(
            static function (?Throwable $error, array $sockets) use (&$remote): void {
                $remote = $sockets[0] ?? null;
            },
        );
        self::assertInstanceOf(RemoteSocket::class, $remote);

        return $remote;
    }

    /**
     * @return array{?Throwable, mixed}
     * @phpstan-impure
     */
    private function callbackResult(): array
    {
        self::assertNotNull($this->result, 'The callback did not run');

        return $this->result;
    }

    /**
     * @return Closure(?Throwable, mixed): void
     */
    private function recorder(): Closure
    {
        return function (?Throwable $error, mixed $responses): void {
            $this->result = [$error, $responses];
        };
    }

    private function server(string $name): SocketIO
    {
        $clock = new FakeScheduler();
        $io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: $clock,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(0x100 * (count($this->servers) + 1)),
        );
        $pubSub = $this->broker->connect($name);
        $io->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $pubSub));
        $this->servers[$name] = $io;
        $this->clocks[$name] = $clock;

        return $io;
    }

    /**
     * @param array<string, mixed>|null $auth
     * @return array{SocketIOClient, Socket}
     */
    private function connect(string $name, ?array $auth = null): array
    {
        $io = $this->servers[$name];
        $client = new SocketIOClient($io);
        $client->send($auth === null ? '0' : '0' . json_encode($auth));
        $client->received();
        $sockets = $io->sockets->sockets();

        return [$client, $sockets[array_key_last($sockets)]];
    }

    private function servers(string $name, string $namespace = '/'): int
    {
        return $this->servers[$name]->of($namespace)->adapter()->serverCount();
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    private function published(): array
    {
        return $this->broker->published;
    }

    /**
     * @return list<mixed> the type of each message published since the test cleared them
     */
    private function types(): array
    {
        return array_map(
            static fn (array $published): mixed => $published[1]['type'] ?? null,
            $this->broker->published,
        );
    }
}
