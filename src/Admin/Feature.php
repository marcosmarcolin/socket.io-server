<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Admin;

/**
 * What the Admin UI may show and do, sent in its `config` event, with the reference's names.
 */
enum Feature: string
{
    case Emit = 'EMIT';
    case Join = 'JOIN';
    case Leave = 'LEAVE';
    case Disconnect = 'DISCONNECT';
    case MJoin = 'MJOIN';
    case MLeave = 'MLEAVE';
    case MDisconnect = 'MDISCONNECT';
    case AggregatedEvents = 'AGGREGATED_EVENTS';
    case AllEvents = 'ALL_EVENTS';
}
