<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Protocol;

use WeakMap;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket as WorkermanWebSocket;

/**
 * Workerman's WebSocket protocol, plus whether each message arrived as a text or a binary frame.
 */
final class WebSocket extends WorkermanWebSocket
{
    /** @var WeakMap<TcpConnection, bool>|null whether the last message on each connection was binary */
    private static ?WeakMap $binary = null;

    public static function decode(string $buffer, TcpConnection $connection): string
    {
        $opcode = ord($buffer[0]) & 0xf;
        // Continuation (0x0) and control frames (0x8 and up) keep the opcode of the message's first frame.
        if ($opcode === 0x1 || $opcode === 0x2) {
            self::binaryMap()[$connection] = $opcode === 0x2;
        }

        return parent::decode($buffer, $connection);
    }

    /**
     * Whether the message just delivered to onMessage came in a binary frame.
     */
    public static function isBinary(TcpConnection $connection): bool
    {
        return self::binaryMap()[$connection] ?? false;
    }

    /**
     * Sends one message as a text or a binary frame, deflated when $compress, which needs permessage-deflate
     * accepted in the handshake.
     */
    public static function send(TcpConnection $connection, string $data, bool $binary, bool $compress = false): void
    {
        // Workerman picks the frame type, and whether to deflate it, from this property; it is only touched here.
        $connection->websocketType = match (true) {
            $binary && $compress => self::BINARY_TYPE_ARRAYBUFFER_DEFLATE,
            $binary => self::BINARY_TYPE_ARRAYBUFFER,
            $compress => self::BINARY_TYPE_BLOB_DEFLATE,
            default => self::BINARY_TYPE_BLOB,
        };
        $connection->send($data);
        $connection->websocketType = self::BINARY_TYPE_BLOB;
    }

    /**
     * @return WeakMap<TcpConnection, bool>
     */
    private static function binaryMap(): WeakMap
    {
        return self::$binary ??= new WeakMap();
    }
}
