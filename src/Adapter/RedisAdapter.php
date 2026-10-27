<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use Marcolin\SocketIO\Adapter\Redis\RedisPubSub;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Adapter\Redis\WorkermanRedis;
use Marcolin\SocketIO\Nsp;
use Throwable;

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
     * @param string $url redis://[[user]:password@]host[:port], or rediss:// for TLS
     * @param string $key the first part of every channel name; servers of other apps on the same Redis need another
     * @param (Closure(Throwable): void)|null $onError receives connection failures and Redis errors; Worker::log by
     *     default
     * @param array<string, mixed> $tls PHP's ssl context options for rediss://, such as cafile or local_cert
     * @return Closure(Nsp): Adapter
     * @throws \InvalidArgumentException for an invalid URL, or TLS options without rediss://
     * @throws \LogicException outside the event loop, or for rediss:// on Swoole's event loop
     */
    public static function factory(
        string $url = RedisUrl::DEFAULT,
        string $key = 'socket.io',
        ?Closure $onError = null,
        array $tls = [],
    ): Closure {
        $redisUrl = RedisUrl::parse($url)->withTlsOptions($tls);
        $loop = WorkermanRedis::start('RedisAdapter', $redisUrl, $onError);
        $pubSub = new RedisPubSub($redisUrl, $loop->dialer, $loop->scheduler, $loop->onError);

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub, $key);
    }
}
