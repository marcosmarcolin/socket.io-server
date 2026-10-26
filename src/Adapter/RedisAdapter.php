<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Adapter\Redis\RedisPubSub;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Adapter\Redis\WorkermanDialer;
use Marcolin\SocketIO\Engine\Workerman\EventLoop;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Marcolin\SocketIO\Nsp;
use Throwable;
use Workerman\Worker;

/**
 * Broadcasts across the processes of a server, on one machine or many, through Redis publish and subscribe.
 */
final class RedisAdapter extends PubSubAdapter
{
    /**
     * An adapter factory for SocketIO::adapter(), on a Redis server.
     *
     * Call it in workerStart: it connects to Redis, which needs the running event loop. It reconnects by itself.
     *
     * @param string $url redis://[[user]:password@]host[:port]
     * @param string $key the first part of every channel name; servers of other apps on the same Redis need another
     * @param (Closure(Throwable): void)|null $onError receives connection failures and Redis errors; Worker::log by
     *     default
     * @return Closure(Nsp): Adapter
     * @throws \InvalidArgumentException for an invalid URL
     * @throws LogicException outside the event loop
     */
    public static function factory(
        string $url = RedisUrl::DEFAULT,
        string $key = 'socket.io',
        ?Closure $onError = null,
    ): Closure {
        $redisUrl = RedisUrl::parse($url);
        if (! EventLoop::runs()) {
            throw new LogicException(
                'RedisAdapter needs the running Workerman event loop. Set it in workerStart: '
                . '$io->on(\'workerStart\', fn () => $io->adapter(RedisAdapter::factory()));',
            );
        }
        $pubSub = new RedisPubSub(
            $redisUrl,
            new WorkermanDialer(),
            new WorkermanScheduler(),
            $onError ?? static function (Throwable $error): void {
                Worker::log((string) $error);
            },
        );

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub, $key);
    }
}
