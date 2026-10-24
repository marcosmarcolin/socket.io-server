<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

enum TransportState
{
    case Open;
    case Closing;
    case Closed;
}
