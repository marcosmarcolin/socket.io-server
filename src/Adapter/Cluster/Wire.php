<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Cluster;

use Marcolin\SocketIO\Adapter\BroadcastOptions;
use Marcolin\SocketIO\Binary;

/**
 * The shape of the messages servers exchange: arrays of scalars and lists, never objects, shared by PubSubAdapter
 * and the Emitter.
 *
 * @internal
 */
final class Wire
{
    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function message(string $uid, MessageType $type, array $fields = []): array
    {
        return ['uid' => $uid, 'type' => $type->value] + $fields;
    }

    /**
     * @return array{rooms: list<string>, except: list<string>, volatile: bool, timeout: int|null, compress: bool}
     */
    public static function options(BroadcastOptions $options): array
    {
        return [
            'rooms' => $options->rooms,
            'except' => $options->except,
            'volatile' => $options->volatile,
            'timeout' => $options->timeout,
            'compress' => $options->compress,
        ];
    }

    /**
     * Encoded packet parts, as ['text'|'binary', string].
     *
     * @param list<string|Binary> $parts
     * @return list<array{string, string}>
     */
    public static function parts(array $parts): array
    {
        return array_map(
            static fn (string|Binary $part): array => $part instanceof Binary
                ? ['binary', $part->data]
                : ['text', $part],
            $parts,
        );
    }
}
