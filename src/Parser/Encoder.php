<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Parser;

use BackedEnum;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Marcolin\SocketIO\Binary;
use stdClass;

/**
 * Encodes Socket.IO v5 packets, as socket.io-parser does.
 */
final class Encoder
{
    // The output of JSON.stringify: slashes, Unicode and line terminators stay as they are.
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    // Also stops circular references, which would otherwise recurse forever.
    private const MAX_DEPTH = 512;

    /**
     * Encodes a packet: the text part first, then one Binary per attachment.
     *
     * An EVENT or ACK with Binary values becomes a BINARY_EVENT or BINARY_ACK with placeholders.
     *
     * @return list<string|Binary>
     * @throws InvalidArgumentException when the packet can't be sent
     */
    public function encode(Packet $packet): array
    {
        self::validate($packet);

        /** @var list<Binary> $attachments */
        $attachments = [];
        $data = self::deconstruct($packet->data, $attachments, 0);
        if ($attachments === []) {
            return [self::encodeAsString($packet->type, null, $packet->nsp, $packet->id, $packet->data)];
        }
        if ($packet->type !== PacketType::Event && $packet->type !== PacketType::Ack) {
            throw new InvalidArgumentException(sprintf(
                'Binary data can only be sent in EVENT and ACK packets, not %s',
                $packet->type->name,
            ));
        }

        $type = $packet->type === PacketType::Event ? PacketType::BinaryEvent : PacketType::BinaryAck;

        return [self::encodeAsString($type, count($attachments), $packet->nsp, $packet->id, $data), ...$attachments];
    }

    private static function encodeAsString(
        PacketType $type,
        ?int $attachments,
        string $nsp,
        ?int $id,
        mixed $data,
    ): string {
        $encoded = (string) $type->value;
        if ($attachments !== null) {
            $encoded .= $attachments . '-';
        }
        if ($nsp !== '/') {
            $encoded .= $nsp . ',';
        }
        if ($id !== null) {
            $encoded .= $id;
        }
        if ($data !== null) {
            try {
                $encoded .= json_encode($data, self::JSON_FLAGS);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('The data can\'t be encoded as JSON: ' . $e->getMessage(), 0, $e);
            }
        }

        return $encoded;
    }

    /**
     * Replaces every Binary with a numbered placeholder, collecting the Binary values.
     *
     * Values without Binary inside are returned unchanged, so json_encode() sees them as given.
     *
     * @param list<Binary> $attachments
     */
    private static function deconstruct(mixed $value, array &$attachments, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('The data is nested too deeply, or contains a circular reference');
        }
        if ($value instanceof Binary) {
            $placeholder = ['_placeholder' => true, 'num' => count($attachments)];
            $attachments[] = $value;

            return $placeholder;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::deconstruct($item, $attachments, $depth + 1);
            }

            return $value;
        }
        if ($value instanceof JsonSerializable) {
            $before = count($attachments);
            $serialized = self::deconstruct($value->jsonSerialize(), $attachments, $depth + 1);

            return count($attachments) > $before ? $serialized : $value;
        }
        if (is_object($value) && ! $value instanceof BackedEnum) {
            // json_encode() writes the public properties, which is what get_object_vars() returns from here.
            $before = count($attachments);
            $copy = new stdClass();
            foreach (get_object_vars($value) as $name => $item) {
                $copy->{$name} = self::deconstruct($item, $attachments, $depth + 1);
            }

            return count($attachments) > $before ? $copy : $value;
        }

        return $value;
    }

    private static function validate(Packet $packet): void
    {
        if (! str_starts_with($packet->nsp, '/') || str_contains($packet->nsp, ',')) {
            throw new InvalidArgumentException(sprintf(
                'A namespace starts with "/" and has no comma, got "%s"',
                $packet->nsp,
            ));
        }
        if ($packet->id !== null && $packet->id < 0) {
            throw new InvalidArgumentException('An acknowledgement id can\'t be negative');
        }

        $data = $packet->data;
        $valid = match ($packet->type) {
            PacketType::Connect => $data === null || self::isObject($data),
            PacketType::Disconnect => $data === null,
            PacketType::Event => is_array($data) && array_is_list($data) && $data !== []
                && (is_string($data[0]) || is_int($data[0]) || is_float($data[0])),
            PacketType::Ack => is_array($data) && array_is_list($data),
            PacketType::ConnectError => is_string($data) || self::isObject($data),
            PacketType::BinaryEvent, PacketType::BinaryAck => throw new InvalidArgumentException(
                'Send EVENT or ACK: the encoder picks the binary type when the data has Binary values',
            ),
        };
        if (! $valid) {
            throw new InvalidArgumentException(sprintf('Invalid data for a %s packet', $packet->type->name));
        }
    }

    /**
     * Whether the value encodes as a JSON object.
     */
    private static function isObject(mixed $value): bool
    {
        return (is_array($value) && $value !== [] && ! array_is_list($value))
            || (is_object($value) && ! $value instanceof Binary && ! $value instanceof BackedEnum);
    }
}
