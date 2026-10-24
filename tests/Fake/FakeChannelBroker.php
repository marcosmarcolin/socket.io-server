<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use RuntimeException;

/**
 * A channel server in memory, for several fake processes: like workerman/channel, a publish reaches every
 * subscriber of the channel, the publisher included, and the message goes through serialize().
 */
final class FakeChannelBroker
{
    /** @var array<string, array<string, Closure(mixed): void>> listeners by channel, then by process */
    private array $subscriptions = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $published = [];

    public function connect(string $process): FakePubSub
    {
        return new FakePubSub($this, $process);
    }

    /**
     * @param Closure(mixed): void $listener
     */
    public function subscribe(string $process, string $channel, Closure $listener): void
    {
        $this->subscriptions[$channel][$process] = $listener;
    }

    public function unsubscribe(string $process, string $channel): void
    {
        unset($this->subscriptions[$channel][$process]);
    }

    /**
     * @param array<string, mixed> $message
     */
    public function publish(string $channel, array $message): void
    {
        $this->published[] = [$channel, $message];
        // No class may come back: a message with objects would not survive.
        $copy = unserialize(serialize($message), ['allowed_classes' => false]);
        if ($copy != $message) {
            throw new RuntimeException('The message does not survive serialization without classes');
        }
        foreach ($this->subscriptions[$channel] ?? [] as $listener) {
            $listener($copy);
        }
    }

    /**
     * @return list<string> the processes subscribed to the channel
     * @phpstan-impure
     */
    public function subscribers(string $channel): array
    {
        return array_map('strval', array_keys($this->subscriptions[$channel] ?? []));
    }

    /**
     * @return list<string> the channels one process is subscribed to
     */
    public function channelsOf(string $process): array
    {
        $channels = [];
        foreach ($this->subscriptions as $channel => $listeners) {
            if (isset($listeners[$process])) {
                $channels[] = (string) $channel;
            }
        }

        return $channels;
    }
}
