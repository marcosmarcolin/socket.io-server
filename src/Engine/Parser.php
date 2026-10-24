<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * Engine.IO protocol v4 packet encoding.
 */
final class Parser
{
    public const SEPARATOR = "\x1e";

    /**
     * Encodes a packet for a WebSocket frame: binary data is sent as is.
     */
    public static function encodePacket(Packet $packet): string
    {
        return $packet->binary ? $packet->data : $packet->type->value . $packet->data;
    }

    /**
     * Decodes the content of a WebSocket frame.
     */
    public static function decodePacket(string $data, bool $binary = false): Packet
    {
        if ($binary) {
            return new Packet(PacketType::Message, $data, true);
        }
        if ($data === '' || ! ctype_digit($data[0])) {
            throw new InvalidPacketException('Invalid packet type');
        }
        $type = PacketType::tryFrom((int) $data[0]) ?? throw new InvalidPacketException('Unknown packet type');

        return new Packet($type, substr($data, 1));
    }

    /**
     * Encodes packets for an HTTP long-polling response body.
     *
     * @param list<Packet> $packets
     */
    public static function encodePayload(array $packets): string
    {
        return implode(self::SEPARATOR, array_map(
            static fn (Packet $packet): string => $packet->binary
                ? 'b' . base64_encode($packet->data)
                : $packet->type->value . $packet->data,
            $packets,
        ));
    }

    /**
     * Decodes an HTTP long-polling request body.
     *
     * @return list<Packet>
     */
    public static function decodePayload(string $payload): array
    {
        $packets = [];
        foreach (explode(self::SEPARATOR, $payload) as $encoded) {
            if (str_starts_with($encoded, 'b')) {
                $data = base64_decode(substr($encoded, 1), true);
                if ($data === false) {
                    throw new InvalidPacketException('Invalid base64 payload');
                }
                $packets[] = new Packet(PacketType::Message, $data, true);
                continue;
            }
            $packets[] = self::decodePacket($encoded);
        }

        return $packets;
    }
}
