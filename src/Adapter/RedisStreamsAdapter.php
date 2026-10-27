<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use Marcolin\SocketIO\Adapter\Redis\RedisPubSub;
use Marcolin\SocketIO\Adapter\Redis\RedisStream;
use Marcolin\SocketIO\Adapter\Redis\RedisStreamOptions;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Adapter\Redis\WorkermanRedis;
use Marcolin\SocketIO\Nsp;
use Throwable;

/**
 * Broadcasts across the processes of a server through a Redis stream, as the reference's redis-streams-adapter:
 * a process that loses Redis for a while reads what it missed when it comes back, and with connectionStateRecovery
 * a client recovers on any process, without sticky sessions. Needs Redis 6.2 or later, or Valkey.
 *
 * Broadcasts, socketsJoin, socketsLeave and disconnectSockets go through the stream; requests, their responses and
 * heartbeats go through publish and subscribe, as with RedisAdapter.
 */
final class RedisStreamsAdapter extends PubSubAdapter
{
    /**
     * An adapter factory for SocketIO::adapter(), on a Redis server.
     *
     * Call it in workerStart: it connects to Redis, which needs the running event loop. It reconnects by itself.
     * Each process opens three connections: commands, subscriptions, and the blocking read of the stream.
     *
     * @param string $url redis://[[user]:password@]host[:port], or rediss:// for TLS
     * @param string $key the first part of every channel name; servers of other apps on the same Redis need another,
     *     and another streamName
     * @param (Closure(Throwable): void)|null $onError receives connection failures and Redis errors; Worker::log by
     *     default
     * @param array<string, mixed> $tls PHP's ssl context options for rediss://, such as cafile or local_cert
     * @param array<string, mixed> $options streamName, maxLen, readCount, blockTimeInMs and sessionKeyPrefix, as
     *     the reference's; see RedisStreamOptions
     * @return Closure(Nsp): Adapter
     * @throws \InvalidArgumentException for an invalid URL or option, or TLS options without rediss://
     * @throws \LogicException outside the event loop, or for rediss:// on Swoole's event loop
     */
    public static function factory(
        string $url = RedisUrl::DEFAULT,
        string $key = 'socket.io',
        ?Closure $onError = null,
        array $tls = [],
        array $options = [],
    ): Closure {
        $redisUrl = RedisUrl::parse($url)->withTlsOptions($tls);
        $streamOptions = RedisStreamOptions::fromArray($options);
        $loop = WorkermanRedis::start('RedisStreamsAdapter', $redisUrl, $onError);
        $commands = RedisPubSub::connection($redisUrl, $loop->dialer, $loop->scheduler, $loop->onError);
        $commands->open();
        $pubSub = new RedisPubSub($redisUrl, $loop->dialer, $loop->scheduler, $loop->onError, $commands);
        $stream = new RedisStream(
            $commands,
            $redisUrl,
            $loop->dialer,
            $loop->scheduler,
            $loop->onError,
            $streamOptions,
        );

        return static fn (Nsp $nsp): Adapter => new self($nsp, $pubSub, $key, $stream);
    }
}
