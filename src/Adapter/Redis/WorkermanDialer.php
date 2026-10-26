<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Workerman\Connection\AsyncTcpConnection;

/**
 * TCP connections on Workerman's event loop, with TLS when given an ssl context.
 */
final class WorkermanDialer implements Dialer
{
    /**
     * @param array<string, mixed>|null $ssl PHP's ssl stream context options, or null for plain TCP
     */
    public function __construct(private readonly ?array $ssl = null)
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
        $connection = $this->ssl === null
            ? new AsyncTcpConnection('tcp://' . $host . ':' . $port)
            : new AsyncTcpConnection('ssl://' . $host . ':' . $port, ['ssl' => $this->ssl]);
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
