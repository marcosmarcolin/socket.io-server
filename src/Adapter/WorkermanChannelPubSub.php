<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Channel\Client;
use Closure;
use LogicException;
use Marcolin\SocketIO\Engine\Workerman\EventLoop;

/**
 * PubSub on a workerman/channel server. The only class that touches Channel\Client, which is static.
 */
final class WorkermanChannelPubSub implements PubSub
{
    /**
     * Connects to the channel server. It needs the running event loop: create it in workerStart.
     *
     * @throws LogicException outside the event loop, or without workerman/channel installed
     */
    public function __construct(string $host = '127.0.0.1', int $port = 2206)
    {
        if (! class_exists(Client::class)) {
            throw new LogicException('ChannelAdapter needs workerman/channel: composer require workerman/channel');
        }
        if (! EventLoop::runs()) {
            throw new LogicException(
                'ChannelAdapter needs the running Workerman event loop. Set it in workerStart: '
                . '$io->on(\'workerStart\', fn () => $io->adapter(ChannelAdapter::factory()));',
            );
        }

        Client::connect($host, $port);
        // Absorbs messages still in flight for a channel just unsubscribed, which Channel\Client would throw on.
        if (Client::$onMessage === null) {
            Client::$onMessage = static function (): void {
            };
        }
    }

    public function subscribe(string $channel, Closure $listener): void
    {
        Client::on($channel, $listener);
    }

    public function unsubscribe(string $channel): void
    {
        Client::unsubscribe($channel);
    }

    public function publish(string $channel, array $message): void
    {
        Client::publish($channel, $message);
    }
}
