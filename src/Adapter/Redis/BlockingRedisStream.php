<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Marcolin\SocketIO\Adapter\Appender;
use RuntimeException;

/**
 * Appends to the Redis stream of RedisStreamsAdapter with blocking I/O, for the Emitter, over the connection of a
 * BlockingRedisPublisher.
 */
final class BlockingRedisStream implements Appender
{
    public function __construct(
        private readonly BlockingRedisPublisher $connection,
        private readonly RedisStreamOptions $options,
    ) {
    }

    /**
     * @throws RuntimeException when Redis can't be reached, refuses the login, or answers with an error
     */
    public function append(string $nsp, array $message): void
    {
        $this->connection->command(...RedisStream::appendCommand($this->options, $nsp, $message));
    }
}
