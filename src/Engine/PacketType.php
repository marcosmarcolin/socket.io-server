<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

enum PacketType: int
{
    case Open = 0;
    case Close = 1;
    case Ping = 2;
    case Pong = 3;
    case Message = 4;
    case Upgrade = 5;
    case Noop = 6;
}
