<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

final class Packet
{
    public function __construct(
        public readonly PacketType $type,
        public readonly string $data = '',
        public readonly bool $binary = false,
    ) {
    }
}
