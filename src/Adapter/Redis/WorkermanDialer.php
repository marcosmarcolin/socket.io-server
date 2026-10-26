<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Workerman\Connection\AsyncTcpConnection;

/**
 * TCP connections on Workerman's event loop.
 */
final class WorkermanDialer implements Dialer
{
    public function dial(
        string $host,
        int $port,
        Closure $onOpen,
        Closure $onData,
        Closure $onError,
        Closure $onClose,
    ): Link {
        $connection = new AsyncTcpConnection('tcp://' . $host . ':' . $port);
        $connection->onConnect = static function () use ($onOpen): void {
            $onOpen();
        };
        $connection->onMessage = static function (AsyncTcpConnection $connection, mixed $data) use ($onData): void {
            $onData(is_string($data) ? $data : '');
        };
        $connection->onError = static function (mixed $connection, int $code, mixed $message) use ($onError): void {
            $onError(is_string($message) ? $message : 'connection error ' . $code);
        };
        $connection->onClose = static function () use ($onClose): void {
            $onClose();
        };
        $link = new WorkermanLink($connection);
        $connection->connect();

        return $link;
    }
}
