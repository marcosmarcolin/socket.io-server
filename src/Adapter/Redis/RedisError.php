<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

/**
 * An error reply from Redis, such as "WRONGPASS invalid username-password pair".
 */
final class RedisError
{
    public function __construct(public readonly string $message)
    {
    }
}
