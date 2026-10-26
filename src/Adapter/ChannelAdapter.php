<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use Marcolin\SocketIO\Nsp;

/**
 * Broadcasts across the processes of a server through a workerman/channel server.
 */
final class ChannelAdapter extends PubSubAdapter
{
    /**
     * An adapter factory for SocketIO::adapter(), on a workerman/channel server.
     *
     * Call it in workerStart: it connects to the channel server, which needs the running event loop.
     *
     * @return Closure(Nsp): Adapter
     */
    public static function factory(string $host = '127.0.0.1', int $port = 2206): Closure
    {
        $pubSub = new WorkermanChannelPubSub($host, $port);

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub);
    }
}
