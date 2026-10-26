<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use Marcolin\SocketIO\Adapter\Redis\BlockingRedisPublisher;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Adapter\Redis\Resp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The blocking publisher over a local socket pair: the test plays Redis, writing its replies ahead.
 */
final class BlockingRedisPublisherTest extends TestCase
{
    /** @var list<resource> the server ends of the connections, in order */
    private array $servers = [];

    public function testPublishesTheSerializedMessage(): void
    {
        $publisher = $this->publisher(RedisUrl::DEFAULT, [":1\r\n"]);

        $publisher->publish('socket.io#/#', ['uid' => 'emitter', 'type' => 3]);

        self::assertSame(
            [['PUBLISH', 'socket.io#/#', serialize(['uid' => 'emitter', 'type' => 3])]],
            $this->commands(0),
        );
    }

    public function testLogsInFirstAndKeepsTheConnection(): void
    {
        $publisher = $this->publisher('redis://app:secret@localhost', ["+OK\r\n:0\r\n:2\r\n"]);

        $publisher->publish('a', ['n' => 1]);
        $publisher->publish('b', ['n' => 2]);

        self::assertCount(1, $this->servers);
        self::assertSame(
            [
                ['AUTH', 'app', 'secret'],
                ['PUBLISH', 'a', serialize(['n' => 1])],
                ['PUBLISH', 'b', serialize(['n' => 2])],
            ],
            $this->commands(0),
        );
    }

    public function testARefusedLoginThrowsWithoutThePassword(): void
    {
        $publisher = $this->publisher('redis://:s3cret@localhost', ["-WRONGPASS invalid password\r\n"]);

        try {
            $publisher->publish('a', []);
            self::fail('The publish went through');
        } catch (RuntimeException $error) {
            self::assertSame('Redis refused the login: WRONGPASS invalid password', $error->getMessage());
            self::assertStringNotContainsString('s3cret', $error->getMessage());
        }
    }

    public function testAnErrorReplyThrows(): void
    {
        $publisher = $this->publisher(RedisUrl::DEFAULT, ["-ERR wrong type\r\n"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERR wrong type');

        $publisher->publish('a', []);
    }

    public function testReconnectsOnceWhenAKeptConnectionDropped(): void
    {
        $publisher = $this->publisher(RedisUrl::DEFAULT, [":1\r\n", ":1\r\n"]);
        $publisher->publish('a', []);

        // Redis closed the idle connection.
        fclose($this->servers[0]);
        $publisher->publish('b', []);

        self::assertCount(2, $this->servers);
        self::assertSame([['PUBLISH', 'b', serialize([])]], $this->commands(1));
    }

    public function testAFreshConnectionThatFailsThrows(): void
    {
        // The server end closes before any reply.
        $publisher = $this->publisher(RedisUrl::DEFAULT, [null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lost the connection to Redis');

        $publisher->publish('a', []);
    }

    public function testAPartialReplyIsNotAReply(): void
    {
        $publisher = new BlockingRedisPublisher(RedisUrl::parse(RedisUrl::DEFAULT), 0.2, function () {
            [$client, $server] = $this->pair();
            $this->servers[] = $server;
            // A reply without its line end.
            fwrite($server, ':1');

            return $client;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no reply in time');

        $publisher->publish('a', []);
    }

    public function testRepliesReadTogetherServeTheirCommandsInOrder(): void
    {
        // The login's reply and both publishes' replies arrive in one read.
        $publisher = $this->publisher('redis://:pw@localhost', ["+OK\r\n:1\r\n-ERR second failed\r\n"]);

        $publisher->publish('a', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ERR second failed');

        $publisher->publish('b', []);
    }

    public function testCannotConnect(): void
    {
        $publisher = new BlockingRedisPublisher(RedisUrl::parse('redis://127.0.0.1:1'), 0.5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to Redis at 127.0.0.1:1');

        $publisher->publish('a', []);
    }

    public function testAFailedTlsHandshakeSaysWhy(): void
    {
        // Accepts TCP connections in its backlog but never answers the TLS handshake.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $port = (int) substr((string) stream_socket_get_name($server, false), strlen('127.0.0.1:'));
        $publisher = new BlockingRedisPublisher(RedisUrl::parse('rediss://127.0.0.1:' . $port), 0.3);

        try {
            $publisher->publish('a', []);
            self::fail('The publish went through');
        } catch (RuntimeException $error) {
            self::assertStringStartsWith('Cannot connect to Redis at 127.0.0.1:' . $port . ': ', $error->getMessage());
            self::assertStringContainsStringIgnoringCase('crypto', $error->getMessage());
        } finally {
            fclose($server);
        }
    }

    /**
     * A publisher whose every connection is a new socket pair; each entry of $replies is written ahead on the
     * next connection, and null closes it at once.
     *
     * @param list<string|null> $replies
     */
    private function publisher(string $url, array $replies): BlockingRedisPublisher
    {
        return new BlockingRedisPublisher(RedisUrl::parse($url), 1.0, function () use (&$replies) {
            [$client, $server] = $this->pair();
            $reply = array_shift($replies);
            if ($reply === null) {
                fclose($server);
            } else {
                fwrite($server, $reply);
                $this->servers[] = $server;
            }

            return $client;
        });
    }

    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return [$pair[0], $pair[1]];
    }

    /**
     * The commands one connection sent.
     *
     * @return list<list<mixed>>
     */
    private function commands(int $connection): array
    {
        $server = $this->servers[$connection];
        stream_set_blocking($server, false);
        $bytes = (string) stream_get_contents($server);

        $commands = [];
        foreach ((new Resp())->add($bytes) as $reply) {
            if (is_array($reply)) {
                $commands[] = array_values($reply);
            }
        }

        return $commands;
    }
}
