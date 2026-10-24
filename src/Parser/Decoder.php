<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Parser;

use InvalidArgumentException;
use JsonException;
use Marcolin\SocketIO\Binary;

/**
 * Decodes Socket.IO v5 packets, as socket.io-parser does. One decoder per connection: a binary packet spans
 * several messages, its text part and then its attachments.
 *
 * It is stricter than the reference in one place: the spec makes the payload of EVENT and ACK mandatory.
 */
final class Decoder
{
    public const DEFAULT_MAX_ATTACHMENTS = 10;

    /** The binary packet waiting for its attachments. */
    private ?Packet $pending = null;

    private int $expectedAttachments = 0;

    /** @var list<Binary> */
    private array $attachments = [];

    public function __construct(private readonly int $maxAttachments = self::DEFAULT_MAX_ATTACHMENTS)
    {
        if ($maxAttachments < 1) {
            throw new InvalidArgumentException('maxAttachments must be at least 1');
        }
    }

    /**
     * Takes the next message of the connection.
     *
     * @return Packet|null the packet once complete, null while it waits for attachments
     * @throws InvalidPacketException when the message breaks the protocol
     */
    public function add(string|Binary $message): ?Packet
    {
        if ($message instanceof Binary) {
            return $this->addAttachment($message);
        }
        if ($this->pending !== null) {
            throw new InvalidPacketException('got plaintext data when reconstructing a packet');
        }

        [$packet, $attachments] = $this->decodeString($message);
        if ($attachments === null) {
            return $packet;
        }
        $this->pending = $packet;
        $this->expectedAttachments = $attachments;

        return null;
    }

    /**
     * Drops a binary packet still waiting for attachments.
     */
    public function reset(): void
    {
        $this->pending = null;
        $this->expectedAttachments = 0;
        $this->attachments = [];
    }

    private function addAttachment(Binary $attachment): ?Packet
    {
        $pending = $this->pending;
        if ($pending === null) {
            throw new InvalidPacketException('got binary data when not reconstructing a packet');
        }
        $this->attachments[] = $attachment;
        if (count($this->attachments) < $this->expectedAttachments) {
            return null;
        }

        $data = self::reconstruct($pending->data, $this->attachments);
        $this->reset();

        return new Packet(
            $pending->type === PacketType::BinaryEvent ? PacketType::Event : PacketType::Ack,
            $pending->nsp,
            $data,
            $pending->id,
        );
    }

    /**
     * @return array{Packet, int|null} the packet, and its attachment count when it is binary
     */
    private function decodeString(string $message): array
    {
        $type = $message !== '' && ctype_digit($message[0]) ? PacketType::tryFrom((int) $message[0]) : null;
        if ($type === null) {
            throw new InvalidPacketException('unknown packet type ' . ($message[0] ?? ''));
        }
        $position = 1;

        $attachments = null;
        if ($type->isBinary()) {
            $dash = strpos($message, '-', $position);
            $count = $dash === false ? '' : substr($message, $position, $dash - $position);
            if ($dash === false || ! ctype_digit($count) || (int) $count < 1) {
                throw new InvalidPacketException('Illegal attachments');
            }
            if ((int) $count > $this->maxAttachments) {
                throw new InvalidPacketException('too many attachments');
            }
            $attachments = (int) $count;
            $position = $dash + 1;
        }

        $nsp = '/';
        if (($message[$position] ?? '') === '/') {
            // There is no escaping: the namespace runs to the first comma.
            $comma = strpos($message, ',', $position);
            $end = $comma === false ? strlen($message) : $comma;
            $nsp = substr($message, $position, $end - $position);
            $position = $comma === false ? $end : $comma + 1;
        }

        $id = null;
        $digits = strspn($message, '0123456789', $position);
        if ($digits > 0) {
            $id = self::parseId(substr($message, $position, $digits));
            $position += $digits;
        }

        $payload = substr($message, $position);

        return [new Packet($type, $nsp, self::decodePayload($type, $payload), $id), $attachments];
    }

    /**
     * Digits to an int; leading zeros are fine, as with Number() in the reference.
     */
    private static function parseId(string $digits): int
    {
        $digits = ltrim($digits, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidPacketException('invalid acknowledgement id');
        }

        return (int) $digits;
    }

    private static function decodePayload(PacketType $type, string $payload): mixed
    {
        if ($payload === '') {
            return match ($type) {
                PacketType::Connect, PacketType::Disconnect => null,
                default => throw new InvalidPacketException('invalid payload'),
            };
        }

        // json_decode() gives arrays for both JSON objects and arrays, so the first character tells them apart.
        $first = ltrim($payload, " \t\n\r")[0] ?? '';
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidPacketException('invalid payload');
        }

        $valid = match ($type) {
            PacketType::Connect => $first === '{',
            PacketType::Disconnect => false,
            PacketType::Event, PacketType::BinaryEvent => $first === '['
                && is_array($data)
                && self::isEventName($data[0] ?? null),
            PacketType::Ack, PacketType::BinaryAck => $first === '[',
            PacketType::ConnectError => $first === '{' || is_string($data),
        };
        if (! $valid) {
            throw new InvalidPacketException('invalid payload');
        }

        return $data;
    }

    private static function isEventName(mixed $name): bool
    {
        return is_int($name) || is_float($name)
            || (is_string($name) && ! in_array($name, Packet::RESERVED_EVENTS, true));
    }

    /**
     * Puts the attachments back in place of their placeholders.
     *
     * @param list<Binary> $attachments
     */
    private static function reconstruct(mixed $data, array $attachments): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        if (($data['_placeholder'] ?? null) === true) {
            $num = $data['num'] ?? null;
            if (! is_int($num) || ! isset($attachments[$num])) {
                throw new InvalidPacketException('illegal attachments');
            }

            return $attachments[$num];
        }
        foreach ($data as $key => $value) {
            $data[$key] = self::reconstruct($value, $attachments);
        }

        return $data;
    }
}
