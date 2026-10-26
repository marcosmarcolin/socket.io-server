<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use Closure;
use Marcolin\SocketIO\Adapter\Redis\Dialer;
use Marcolin\SocketIO\Adapter\Redis\Link;
use Marcolin\SocketIO\Adapter\Redis\RedisConnection;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Tests\Fake\FakeDialer;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class RedisConnectionTest extends TestCase
{
    private FakeDialer $dialer;

    private FakeScheduler $scheduler;

    private int $readyCount = 0;

    /** @var list<mixed> */
    private array $replies = [];

    /** @var list<Throwable> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->dialer = new FakeDialer();
        $this->scheduler = new FakeScheduler();
    }

    public function testDialsTheUrlsHostAndPort(): void
    {
        $this->connection('redis://cache:6380')->open();

        self::assertSame(['cache:6380'], $this->dialer->addresses);
    }

    public function testKeepsCommandsUntilConnected(): void
    {
        $connection = $this->connection();
        $connection->open();

        $connection->send('PUBLISH', 'ch', 'a');
        self::assertFalse($connection->isReady());
        self::assertSame([], $this->dialer->last()->commands());

        $this->dialer->last()->open();

        self::assertTrue($connection->isReady());
        self::assertSame(1, $this->readyCount);
        self::assertSame([['PUBLISH', 'ch', 'a']], $this->dialer->last()->commands());
    }

    public function testSendsAtOnceWhenReady(): void
    {
        $connection = $this->ready();

        $connection->send('PING');

        self::assertSame([['PING']], $this->dialer->last()->commands());
    }

    public function testLogsInBeforeAnyCommand(): void
    {
        $connection = $this->connection('redis://app:secret@localhost');
        $connection->open();
        $connection->send('PING');
        $link = $this->dialer->last();

        $link->open();
        self::assertFalse($connection->isReady());
        self::assertSame([['AUTH', 'app', 'secret']], $link->commands());

        $link->receive("+OK\r\n");

        self::assertTrue($connection->isReady());
        self::assertSame([['PING']], $link->commands());
        self::assertSame([], $this->replies);
    }

    public function testARefusedLoginIsReportedAndRetried(): void
    {
        $connection = $this->connection('redis://:wrong@localhost');
        $connection->open();
        $this->dialer->last()->open();

        $this->dialer->last()->receive("-WRONGPASS invalid username-password pair\r\n");

        self::assertFalse($connection->isReady());
        self::assertTrue($this->dialer->last()->closed);
        self::assertSame([
            'Cannot connect to Redis at localhost:6379: Redis refused the login: '
            . 'WRONGPASS invalid username-password pair; retrying every second',
        ], $this->messages());

        $this->scheduler->advance(RedisConnection::RECONNECT_DELAY);
        self::assertCount(2, $this->dialer->links);
    }

    public function testDeliversRepliesAndReportsErrorReplies(): void
    {
        $this->ready();

        $this->dialer->last()->receive(":1\r\n-ERR unknown command\r\n*2\r\n+a\r\n:2\r\n");

        self::assertSame([1, ['a', 2]], $this->replies);
        self::assertSame(['Redis error: ERR unknown command'], $this->messages());
    }

    public function testAnExceptionFromAReplyListenerIsReported(): void
    {
        $connection = new RedisConnection(
            RedisUrl::parse(RedisUrl::DEFAULT),
            $this->dialer,
            $this->scheduler,
            static function (): void {
            },
            static function (): void {
                throw new RuntimeException('listener failed');
            },
            function (Throwable $error): void {
                $this->errors[] = $error;
            },
        );
        $connection->open();
        $this->dialer->last()->open();

        $this->dialer->last()->receive(":1\r\n:2\r\n");

        self::assertSame(['listener failed', 'listener failed'], $this->messages());
    }

    public function testReconnectsASecondAfterTheConnectionDrops(): void
    {
        $connection = $this->ready();

        $this->dialer->last()->drop();
        self::assertFalse($connection->isReady());
        self::assertSame(['Lost the connection to Redis; reconnecting every second'], $this->messages());

        $this->scheduler->advance(RedisConnection::RECONNECT_DELAY - 1);
        self::assertCount(1, $this->dialer->links);
        $this->scheduler->advance(1);
        self::assertCount(2, $this->dialer->links);

        $this->dialer->last()->open();
        self::assertTrue($connection->isReady());
        self::assertSame(2, $this->readyCount);
    }

    public function testReportsAFailureToConnectOnceUntilConnected(): void
    {
        $connection = $this->connection();
        $connection->open();

        for ($i = 0; $i < 3; $i++) {
            $this->dialer->last()->fail('Connection refused');
            $this->scheduler->advance(RedisConnection::RECONNECT_DELAY);
        }

        self::assertSame(
            ['Cannot connect to Redis at 127.0.0.1:6379: Connection refused; retrying every second'],
            $this->messages(),
        );

        $this->dialer->last()->open();
        $this->dialer->last()->fail('Connection reset');

        self::assertSame([
            'Cannot connect to Redis at 127.0.0.1:6379: Connection refused; retrying every second',
            'Redis connection error: Connection reset',
            'Lost the connection to Redis; reconnecting every second',
        ], $this->messages());
        self::assertFalse($connection->isReady());
    }

    public function testACloseBeforeDialReturnsStillReconnects(): void
    {
        $dialer = new class ($this->dialer) implements Dialer {
            public function __construct(private readonly FakeDialer $inner)
            {
            }

            public function dial(
                string $host,
                int $port,
                Closure $onOpen,
                Closure $onData,
                Closure $onError,
                Closure $onClose,
            ): Link {
                $link = $this->inner->dial($host, $port, $onOpen, $onData, $onError, $onClose);
                if (count($this->inner->links) === 1) {
                    $this->inner->last()->fail('getaddrinfo failed');
                }

                return $link;
            }
        };
        $connection = new RedisConnection(
            RedisUrl::parse(RedisUrl::DEFAULT),
            $dialer,
            $this->scheduler,
            static function (): void {
            },
            static function (): void {
            },
            static function (): void {
            },
        );

        $connection->open();
        $connection->send('PING');
        $this->scheduler->advance(RedisConnection::RECONNECT_DELAY);
        $this->dialer->last()->open();

        self::assertSame([['PING']], $this->dialer->last()->commands());
        self::assertSame([], $this->dialer->links[0]->sent);
    }

    public function testIgnoresCallbacksOfAnOldLink(): void
    {
        $connection = $this->ready();
        $old = $this->dialer->last();
        $old->drop();
        $this->scheduler->advance(RedisConnection::RECONNECT_DELAY);

        $old->receive(":1\r\n");
        $old->open();

        self::assertSame([], $this->replies);
        self::assertFalse($connection->isReady());
    }

    public function testDropsCommandsBeyondTheQueueLimit(): void
    {
        $connection = $this->connection();
        $connection->open();

        for ($i = 0; $i <= RedisConnection::MAX_QUEUED; $i++) {
            $connection->send('PUBLISH', 'ch', (string) $i);
        }
        $this->dialer->last()->open();

        $commands = $this->dialer->last()->commands();
        self::assertCount(RedisConnection::MAX_QUEUED, $commands);
        self::assertSame(['PUBLISH', 'ch', (string) (RedisConnection::MAX_QUEUED - 1)], end($commands));
    }

    public function testAMalformedReplyIsReportedAndClosesTheConnection(): void
    {
        $this->ready();

        $this->dialer->last()->receive("?\r\n");

        self::assertTrue($this->dialer->last()->closed);
        self::assertSame("Unknown Redis reply type: '?'", $this->messages()[0]);
    }

    // Helpers

    private function connection(string $url = RedisUrl::DEFAULT): RedisConnection
    {
        return new RedisConnection(
            RedisUrl::parse($url),
            $this->dialer,
            $this->scheduler,
            function (): void {
                ++$this->readyCount;
            },
            function (mixed $reply): void {
                $this->replies[] = $reply;
            },
            function (Throwable $error): void {
                $this->errors[] = $error;
            },
        );
    }

    private function ready(): RedisConnection
    {
        $connection = $this->connection();
        $connection->open();
        $this->dialer->last()->open();

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn (Throwable $error): string => $error->getMessage(), $this->errors);
    }
}
