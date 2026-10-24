<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use RuntimeException;

/**
 * The client side of RFC 6455 framing, for tests.
 */
final class WebSocketFrames
{
    /**
     * The headers of a WebSocket handshake request, each ending with CRLF.
     */
    public static function upgradeHeaders(): string
    {
        // The sample nonce from RFC 6455, section 1.3.
        return "Host: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('the sample nonce') . "\r\nSec-WebSocket-Version: 13\r\n";
    }

    /**
     * A masked frame, as clients send them.
     */
    public static function client(string $payload, int $opcode = 0x1, bool $fin = true): string
    {
        $length = strlen($payload);
        $header = chr(($fin ? 0x80 : 0) | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);

        return $header . $mask . ($payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length));
    }

    /**
     * Splits the server's unmasked frames.
     *
     * @return list<array{opcode: int, data: string}>
     */
    public static function parse(string $bytes): array
    {
        $frames = [];
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $opcode = ord($bytes[$offset]) & 0xf;
            $length = ord($bytes[$offset + 1]) & 0x7f;
            $offset += 2;
            if ($length === 126) {
                $length = self::unpackInt('n', substr($bytes, $offset, 2));
                $offset += 2;
            } elseif ($length === 127) {
                $length = self::unpackInt('J', substr($bytes, $offset, 8));
                $offset += 8;
            }
            $frames[] = ['opcode' => $opcode, 'data' => substr($bytes, $offset, $length)];
            $offset += $length;
        }

        return $frames;
    }

    /**
     * Splits a handshake answer into its HTTP head and the frames sent after it.
     *
     * @return array{string, list<array{opcode: int, data: string}>}
     */
    public static function afterHandshake(string $bytes): array
    {
        $end = strpos($bytes, "\r\n\r\n");
        if ($end === false) {
            throw new RuntimeException('No complete HTTP head in: ' . $bytes);
        }

        return [substr($bytes, 0, $end), self::parse(substr($bytes, $end + 4))];
    }

    private static function unpackInt(string $format, string $bytes): int
    {
        $value = unpack($format, $bytes);
        if ($value === false || ! is_int($value[1] ?? null)) {
            throw new RuntimeException('Bad frame length');
        }

        return $value[1];
    }
}
