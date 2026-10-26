<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use Marcolin\SocketIO\Adapter\Redis\RedisConnection;
use Marcolin\SocketIO\Adapter\Redis\RedisPubSub;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Adapter\Redis\Resp;
use Marcolin\SocketIO\Tests\Fake\FakeDialer;
use Marcolin\SocketIO\Tests\Fake\FakeLink;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use Throwable;
use __PHP_Incomplete_Class;

final class RedisPubSubTest extends TestCase
{
    private FakeDialer $dialer;

    private FakeScheduler $scheduler;

    private RedisPubSub $pubSub;

    /** @var list<Throwable> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->dialer = new FakeDialer();
        $this->scheduler = new FakeScheduler();
        $this->pubSub = new RedisPubSub(
            RedisUrl::parse(RedisUrl::DEFAULT),
            $this->dialer,
            $this->scheduler,
            function (Throwable $error): void {
                $this->errors[] = $error;
            },
        );
    }

    public function testOpensAPublisherAndASubscriberConnection(): void
    {
        self::assertCount(2, $this->dialer->links);
    }

    public function testPublishesTheSerializedMessage(): void
    {
        $this->publisher()->open();

        $this->pubSub->publish('socket.io#/#', ['parts' => [['binary', "\x00\xff"]]]);

        self::assertSame(
            [['PUBLISH', 'socket.io#/#', serialize(['parts' => [['binary', "\x00\xff"]]])]],
            $this->publisher()->commands(),
        );
    }

    public function testSubscribesEveryChannelOnceReady(): void
    {
        $this->pubSub->subscribe('a', static function (): void {
        });
        $this->pubSub->subscribe('b', static function (): void {
        });
        self::assertSame([], $this->subscriber()->commands());

        $this->subscriber()->open();

        self::assertSame([['SUBSCRIBE', 'a', 'b']], $this->subscriber()->commands());
    }

    public function testSubscribesAndUnsubscribesAtOnceWhenReady(): void
    {
        $this->subscriber()->open();

        $this->pubSub->subscribe('a', static function (): void {
        });
        $this->pubSub->subscribe('a', static function (): void {
        });
        $this->pubSub->unsubscribe('a');
        $this->pubSub->unsubscribe('a');

        self::assertSame([['SUBSCRIBE', 'a'], ['UNSUBSCRIBE', 'a']], $this->subscriber()->commands());
    }

    public function testDeliversMessagesToTheChannelsListener(): void
    {
        $received = [];
        $this->pubSub->subscribe('a', static function (mixed $message) use (&$received): void {
            $received[] = $message;
        });
        $this->subscriber()->open();

        $this->subscriber()->receive(
            self::push('subscribe', 'a', 1)
            . self::push('message', 'a', serialize(['n' => 1]))
            . self::push('message', 'other', serialize(['n' => 2])),
        );

        self::assertSame([['n' => 1]], $received);
    }

    public function testTheLatestListenerOfAChannelGetsItsMessages(): void
    {
        $received = [];
        $this->pubSub->subscribe('a', static function (): void {
        });
        $this->pubSub->subscribe('a', static function (mixed $message) use (&$received): void {
            $received[] = $message;
        });
        $this->subscriber()->open();

        $this->subscriber()->receive(self::push('message', 'a', serialize(['n' => 1])));

        self::assertSame([['n' => 1]], $received);
    }

    public function testIgnoresMessagesStillInFlightAfterUnsubscribing(): void
    {
        $received = 0;
        $this->pubSub->subscribe('a', static function () use (&$received): void {
            ++$received;
        });
        $this->subscriber()->open();
        $this->pubSub->unsubscribe('a');

        $this->subscriber()->receive(self::push('message', 'a', serialize(['n' => 1])));

        self::assertSame(0, $received);
    }

    public function testIgnoresPayloadsThatAreNotASerializedArrayWithoutWarnings(): void
    {
        $received = 0;
        $this->pubSub->subscribe('a', static function () use (&$received): void {
            ++$received;
        });
        $this->subscriber()->open();

        $this->subscriber()->receive(
            self::push('message', 'a', 'not serialized')
            . self::push('message', 'a', serialize('a string'))
            . self::push('message', 'a', 'O:8:"stdClass":0:{}'),
        );

        self::assertSame(0, $received);
        self::assertSame([], $this->errors);
    }

    public function testObjectsInAPayloadComeBackIncomplete(): void
    {
        $received = [];
        $this->pubSub->subscribe('a', static function (mixed $message) use (&$received): void {
            $received[] = $message;
        });
        $this->subscriber()->open();

        $this->subscriber()->receive(self::push('message', 'a', serialize(['x' => new ArrayObject()])));

        $message = $received[0] ?? null;
        self::assertIsArray($message);
        self::assertInstanceOf(__PHP_Incomplete_Class::class, $message['x'] ?? null);
    }

    public function testResubscribesAfterReconnecting(): void
    {
        $this->pubSub->subscribe('a', static function (): void {
        });
        $this->pubSub->subscribe('b', static function (): void {
        });
        $this->subscriber()->open();
        $this->subscriber()->commands();
        $this->pubSub->unsubscribe('b');

        $this->subscriber()->drop();
        $this->scheduler->advance(RedisConnection::RECONNECT_DELAY);
        $this->pubSub->subscribe('c', static function (): void {
        });
        $this->dialer->last()->open();

        self::assertSame([['SUBSCRIBE', 'a', 'c']], $this->dialer->last()->commands());
    }

    // Helpers

    private function publisher(): FakeLink
    {
        return $this->dialer->links[0];
    }

    private function subscriber(): FakeLink
    {
        return $this->dialer->links[1];
    }

    private static function push(string $kind, string $channel, string|int $payload): string
    {
        $last = is_int($payload) ? ':' . $payload . "\r\n" : '$' . strlen($payload) . "\r\n" . $payload . "\r\n";

        // A push is an array of three: the kind, the channel, and the payload or count.
        return '*3' . substr(Resp::command($kind, $channel), 2) . $last;
    }
}
