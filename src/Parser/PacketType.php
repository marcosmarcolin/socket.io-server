<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Parser;

enum PacketType: int
{
    case Connect = 0;
    case Disconnect = 1;
    case Event = 2;
    case Ack = 3;
    case ConnectError = 4;
    case BinaryEvent = 5;
    case BinaryAck = 6;

    public function isBinary(): bool
    {
        return $this === self::BinaryEvent || $this === self::BinaryAck;
    }
}
