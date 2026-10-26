<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

final class Packet
{
    /**
     * @param bool $compress whether the transport may compress it; a polling answer is compressed when any of its
     *        packets may be, as in the reference
     */
    public function __construct(
        public readonly PacketType $type,
        public readonly string $data = '',
        public readonly bool $binary = false,
        public readonly bool $compress = true,
    ) {
    }
}
