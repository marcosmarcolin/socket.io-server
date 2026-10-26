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
use Workerman\Events\Swoole;
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
     * @param string $url redis://[[user]:password@]host[:port], or rediss:// for TLS
     * @param string $key the first part of every channel name; servers of other apps on the same Redis need another
     * @param (Closure(Throwable): void)|null $onError receives connection failures and Redis errors; Worker::log by
     *     default
     * @param array<string, mixed> $tls PHP's ssl context options for rediss://, such as cafile or local_cert
     * @return Closure(Nsp): Adapter
     * @throws \InvalidArgumentException for an invalid URL, or TLS options without rediss://
     * @throws LogicException outside the event loop, or for rediss:// on Swoole's event loop
     */
    public static function factory(
        string $url = RedisUrl::DEFAULT,
        string $key = 'socket.io',
        ?Closure $onError = null,
        array $tls = [],
    ): Closure {
        $redisUrl = RedisUrl::parse($url)->withTlsOptions($tls);
        if (! EventLoop::runs()) {
            throw new LogicException(
                'RedisAdapter needs the running Workerman event loop. Set it in workerStart: '
                . '$io->on(\'workerStart\', fn () => $io->adapter(RedisAdapter::factory()));',
            );
        }
        // Workerman's TLS client connections never finish their handshake on Swoole's loop: fail now, not hang.
        if ($redisUrl->tls && Worker::getEventLoop() instanceof Swoole) {
            throw new LogicException(
                'RedisAdapter can\'t use rediss:// on Swoole\'s event loop: Workerman\'s TLS client connections hang '
                . 'there. Use the event or select() loop, or reach Redis through a TLS tunnel.',
            );
        }
        $pubSub = new RedisPubSub(
            $redisUrl,
            new WorkermanDialer($redisUrl->sslContext()),
            new WorkermanScheduler(),
            $onError ?? static function (Throwable $error): void {
                Worker::log((string) $error);
            },
        );

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub, $key);
    }
}
