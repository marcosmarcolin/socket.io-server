<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * Why an Engine.IO session closed. The values match the reference implementation.
 */
enum CloseReason: string
{
    case PingTimeout = 'ping timeout';
    case TransportClose = 'transport close';
    case TransportError = 'transport error';
    case ParseError = 'parse error';
    case ForcedClose = 'forced close';
}
