<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

enum SocketState
{
    case Open;
    case Closing;
    case Closed;
}
