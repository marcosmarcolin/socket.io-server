<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Adapter\Stream;

/**
 * One fake process's connection to a FakeStreamBroker.
 */
final class FakeStream implements Stream
{
    public function __construct(private readonly FakeStreamBroker $broker, private readonly string $process)
    {
    }

    public function append(string $nsp, array $message, Closure $appended): void
    {
        $this->broker->append($nsp, $message, $appended);
    }

    public function subscribe(string $nsp, Closure $listener): void
    {
        $this->broker->subscribe($this->process, $nsp, $listener);
    }

    public function unsubscribe(string $nsp): void
    {
        $this->broker->unsubscribe($this->process, $nsp);
    }

    public function saveSession(string $pid, string $session, int $ttl): void
    {
        $this->broker->saveSession($pid, $session, $ttl);
    }

    public function restoreSession(string $nsp, string $pid, string $offset, Closure $callback): void
    {
        $this->broker->restoreSession($nsp, $pid, $offset, $callback);
    }
}
