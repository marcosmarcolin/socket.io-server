<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use LogicException;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\Engine\Workerman\EventLoop;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Throwable;
use Workerman\Events\Swoole;
use Workerman\Worker;

/**
 * What the Redis adapters need from the running Workerman event loop: a dialer, a scheduler and an error handler.
 *
 * @internal
 */
final class WorkermanRedis
{
    /**
     * @param Closure(Throwable): void $onError
     */
    private function __construct(
        public readonly Dialer $dialer,
        public readonly Scheduler $scheduler,
        public readonly Closure $onError,
    ) {
    }

    /**
     * @param string $adapter the adapter's class name, for the messages
     * @param (Closure(Throwable): void)|null $onError Worker::log by default
     * @throws LogicException outside the event loop, or for rediss:// on Swoole's event loop
     */
    public static function start(string $adapter, RedisUrl $url, ?Closure $onError): self
    {
        if (! EventLoop::runs()) {
            throw new LogicException(sprintf(
                '%1$s needs the running Workerman event loop. Set it in workerStart: '
                . '$io->on(\'workerStart\', fn () => $io->adapter(%1$s::factory()));',
                $adapter,
            ));
        }
        // Workerman's TLS client connections never finish their handshake on Swoole's loop: fail now, not hang.
        if ($url->tls && Worker::getEventLoop() instanceof Swoole) {
            throw new LogicException(sprintf(
                '%s can\'t use rediss:// on Swoole\'s event loop: Workerman\'s TLS client connections hang there. '
                . 'Use the event or select() loop, or reach Redis through a TLS tunnel.',
                $adapter,
            ));
        }

        return new self(
            new WorkermanDialer($url->sslContext()),
            new WorkermanScheduler(),
            $onError ?? static function (Throwable $error): void {
                Worker::log((string) $error);
            },
        );
    }
}
