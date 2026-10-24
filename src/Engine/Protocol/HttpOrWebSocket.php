<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Protocol;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;

/**
 * HTTP and WebSocket on one port: a request with "Upgrade: websocket" switches the connection to WebSocket.
 *
 * TcpConnection reads its protocol again for every package, so the switch takes effect at once.
 * Like Workerman's own protocols, it takes a TcpConnection, so it doesn't declare ProtocolInterface.
 */
final class HttpOrWebSocket
{
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd !== false && self::isUpgrade(substr($buffer, 0, $headerEnd + 2))) {
            $connection->protocol = WebSocket::class;

            return WebSocket::input($buffer, $connection);
        }

        // Incomplete headers stay with Http, which also enforces the header size limit.
        return Http::input($buffer, $connection);
    }

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return Http::decode($buffer, $connection);
    }

    public static function encode(string|Response $data, TcpConnection $connection): string
    {
        return Http::encode($data, $connection);
    }

    private static function isUpgrade(string $headers): bool
    {
        return str_starts_with($headers, 'GET ')
            && preg_match('/\r\nUpgrade:[ \t]*websocket[ \t]*\r\n/i', $headers) === 1;
    }
}
