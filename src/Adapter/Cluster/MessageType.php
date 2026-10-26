<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Cluster;

/**
 * The messages servers exchange through a PubSub, numbered as in the reference's cluster adapter.
 */
enum MessageType: int
{
    case InitialHeartbeat = 1;
    case Heartbeat = 2;
    case Broadcast = 3;
    case SocketsJoin = 4;
    case SocketsLeave = 5;
    case DisconnectSockets = 6;
    case FetchSockets = 7;
    case FetchSocketsResponse = 8;
    case ServerSideEmit = 9;
    case ServerSideEmitResponse = 10;
    case BroadcastClientCount = 11;
    case BroadcastAck = 12;
    case AdapterClose = 13;
}
