<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Marcolin\SocketIO\Adapter\PubSub;
use Marcolin\SocketIO\Engine\Scheduler;
use Throwable;

/**
 * PubSub on Redis, over two connections: a subscribed connection can't publish.
 *
 * Messages are serialized with serialize() and read back without classes. After a reconnection, every channel is
 * subscribed again; messages published while a connection was down are lost, as always with Redis pub/sub.
 */
final class RedisPubSub implements PubSub
{
    private readonly RedisConnection $publisher;

    private readonly RedisConnection $subscriber;

    /** @var array<string, Closure(mixed): void> */
    private array $listeners = [];

    /**
     * @param Closure(Throwable): void $onError
     * @param RedisConnection|null $publisher a connection opened by its owner, shared with RedisStream; else its own
     */
    public function __construct(
        RedisUrl $url,
        Dialer $dialer,
        Scheduler $scheduler,
        Closure $onError,
        ?RedisConnection $publisher = null,
    ) {
        if ($publisher === null) {
            $publisher = self::connection($url, $dialer, $scheduler, $onError);
            $publisher->open();
        }
        $this->publisher = $publisher;
        $this->subscriber = new RedisConnection(
            $url,
            $dialer,
            $scheduler,
            $this->resubscribe(...),
            $this->onReply(...),
            $onError,
        );
        $this->subscriber->open();
    }

    /**
     * A connection for commands only, whose replies need nothing unless a command asks for them.
     *
     * @param Closure(Throwable): void $onError
     */
    public static function connection(
        RedisUrl $url,
        Dialer $dialer,
        Scheduler $scheduler,
        Closure $onError,
    ): RedisConnection {
        return new RedisConnection($url, $dialer, $scheduler, static function (): void {
        }, static function (): void {
        }, $onError);
    }

    public function subscribe(string $channel, Closure $listener): void
    {
        $known = isset($this->listeners[$channel]);
        $this->listeners[$channel] = $listener;
        if (! $known && $this->subscriber->isReady()) {
            $this->subscriber->send('SUBSCRIBE', $channel);
        }
    }

    public function unsubscribe(string $channel): void
    {
        if (! isset($this->listeners[$channel])) {
            return;
        }
        unset($this->listeners[$channel]);
        if ($this->subscriber->isReady()) {
            $this->subscriber->send('UNSUBSCRIBE', $channel);
        }
    }

    public function publish(string $channel, array $message): void
    {
        $this->publisher->send('PUBLISH', $channel, serialize($message));
    }

    private function resubscribe(): void
    {
        if ($this->listeners !== []) {
            $this->subscriber->send('SUBSCRIBE', ...array_map('strval', array_keys($this->listeners)));
        }
    }

    /**
     * A message pushed to a subscribed channel. Messages for a channel just unsubscribed may still arrive, and
     * other replies (subscription confirmations) need nothing.
     */
    private function onReply(mixed $reply): void
    {
        if (! is_array($reply) || count($reply) !== 3 || $reply[0] !== 'message') {
            return;
        }
        [, $channel, $payload] = $reply;
        $listener = is_string($channel) ? ($this->listeners[$channel] ?? null) : null;
        if ($listener === null || ! is_string($payload)) {
            return;
        }
        $message = self::unserialize($payload);
        if ($message !== null) {
            $listener($message);
        }
    }

    /**
     * A message, or null for anything that isn't a serialized array; RedisStream reads its entries with it too.
     *
     * @internal
     * @return array<mixed>|null
     */
    public static function unserialize(string $payload): ?array
    {
        // A malformed payload makes unserialize() warn; any client of the server can publish to the channel.
        set_error_handler(static fn (): bool => true);
        try {
            $message = unserialize($payload, ['allowed_classes' => false, 'max_depth' => 64]);
        } finally {
            restore_error_handler();
        }

        return is_array($message) ? $message : null;
    }
}
