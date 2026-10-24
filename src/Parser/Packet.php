<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Parser;

/**
 * A Socket.IO packet.
 *
 * JSON objects arrive as associative arrays; send an empty object as new stdClass().
 */
final class Packet
{
    /**
     * Event names the protocol keeps for itself: they can't be sent, and received ones are invalid.
     */
    public const RESERVED_EVENTS = [
        'connect',
        'connect_error',
        'disconnect',
        'disconnecting',
        'newListener',
        'removeListener',
    ];

    /**
     * @param mixed $data the payload; null when there is none
     * @param int|null $id the acknowledgement id
     */
    public function __construct(
        public readonly PacketType $type,
        public readonly string $nsp = '/',
        public readonly mixed $data = null,
        public readonly ?int $id = null,
    ) {
    }
}
