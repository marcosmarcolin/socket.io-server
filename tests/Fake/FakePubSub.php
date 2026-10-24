<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Adapter\PubSub;

/**
 * One fake process's connection to a FakeChannelBroker.
 */
final class FakePubSub implements PubSub
{
    public function __construct(private readonly FakeChannelBroker $broker, private readonly string $process)
    {
    }

    public function subscribe(string $channel, Closure $listener): void
    {
        $this->broker->subscribe($this->process, $channel, $listener);
    }

    public function unsubscribe(string $channel): void
    {
        $this->broker->unsubscribe($this->process, $channel);
    }

    public function publish(string $channel, array $message): void
    {
        $this->broker->publish($channel, $message);
    }
}
