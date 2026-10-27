<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Redis\RedisStreamOptions;
use Marcolin\SocketIO\Adapter\RedisStreamsAdapter;
use Marcolin\SocketIO\Emitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\Events\Swoole;
use Workerman\Worker;

/**
 * RedisStreamsAdapter's factory and options; its behavior is StreamAdapterTest's, its Redis commands RedisStreamTest's.
 */
final class RedisStreamsAdapterTest extends TestCase
{
    public function testTheFactoryChecksTheUrlFirst(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RedisStreamsAdapter::factory('http://127.0.0.1:6379');
    }

    public function testTheFactoryChecksTheOptionsBeforeTheEventLoop(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unknown Redis stream option: streamCount'));

        RedisStreamsAdapter::factory(options: ['streamCount' => 2]);
    }

    public function testTheFactoryNeedsTheEventLoop(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$io->adapter(RedisStreamsAdapter::factory())');

        RedisStreamsAdapter::factory();
    }

    public function testTheFactoryRefusesTlsOnSwoolesLoop(): void
    {
        // The loop object only, without its constructor, which needs the swoole extension.
        Worker::$globalEvent = (new ReflectionClass(Swoole::class))->newInstanceWithoutConstructor();
        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('RedisStreamsAdapter can\'t use rediss:// on Swoole');

            RedisStreamsAdapter::factory('rediss://127.0.0.1:6379');
        } finally {
            Worker::$globalEvent = null;
        }
    }

    public function testTheEmitterChecksItsOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Emitter::redisStreams(options: ['maxLen' => 0]);
    }

    public function testOptionsHaveTheReferencesDefaults(): void
    {
        self::assertEquals(
            new RedisStreamOptions('socket.io', 10_000, 100, 5_000, 'sio:session:'),
            RedisStreamOptions::fromArray([]),
        );
    }

    public function testOptionsReadEveryName(): void
    {
        self::assertEquals(new RedisStreamOptions('s', 1, 2, 3, 'p:'), RedisStreamOptions::fromArray([
            'streamName' => 's',
            'maxLen' => 1,
            'readCount' => 2,
            'blockTimeInMs' => 3,
            'sessionKeyPrefix' => 'p:',
        ]));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'an unknown name' => [['onlyPlaintext' => true], 'Unknown Redis stream option: onlyPlaintext'];
        yield 'an empty stream name' => [['streamName' => ''], '"streamName" must be a non-empty string'];
        yield 'a stream name not a string' => [['streamName' => 1], '"streamName" must be a non-empty string'];
        yield 'an empty key prefix' => [['sessionKeyPrefix' => ''], '"sessionKeyPrefix" must be a non-empty string'];
        yield 'maxLen of zero' => [['maxLen' => 0], '"maxLen" must be a positive integer'];
        yield 'readCount not an integer' => [['readCount' => '10'], '"readCount" must be a positive integer'];
        yield 'a negative block time' => [['blockTimeInMs' => -1], '"blockTimeInMs" must be a positive integer'];
    }

    /**
     * @param array<mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRefusesInvalidOptions(array $options, string $message): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($message));

        RedisStreamOptions::fromArray($options);
    }
}
