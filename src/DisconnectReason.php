<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

/**
 * Why a socket left its namespace. The values are the reference's; `disconnect` listeners get them as strings.
 */
enum DisconnectReason: string
{
    // From the Engine.IO session.
    case TransportClose = 'transport close';
    case TransportError = 'transport error';
    case PingTimeout = 'ping timeout';
    case ParseError = 'parse error';
    case ForcedClose = 'forced close';

    case ServerNamespaceDisconnect = 'server namespace disconnect';
    case ClientNamespaceDisconnect = 'client namespace disconnect';
    case ForcedServerClose = 'forced server close';
}
