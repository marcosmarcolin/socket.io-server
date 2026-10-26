<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Workerman\Connection\AsyncTcpConnection;

/**
 * A Workerman connection to Redis.
 */
final class WorkermanLink implements Link
{
    public function __construct(private readonly AsyncTcpConnection $connection)
    {
    }

    public function send(string $bytes): void
    {
        // Raw: the connection has no protocol, and failures reach the dialer's onError.
        $this->connection->send($bytes, true);
    }

    public function close(): void
    {
        $this->connection->close();
    }
}
