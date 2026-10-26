<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Cluster;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;

/**
 * The other servers of a namespace, known from their messages. One that sends nothing for the timeout is gone.
 *
 * @internal
 */
final class Nodes
{
    /** @var array<string, int> each server's expiry timer, by uid */
    private array $timers = [];

    /**
     * @param Closure(string): void $onGone
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly int $timeout,
        private readonly Closure $onGone,
    ) {
    }

    /**
     * @return bool whether the server is new
     */
    public function seen(string $uid): bool
    {
        $new = ! isset($this->timers[$uid]);
        if (! $new) {
            $this->scheduler->cancel($this->timers[$uid]);
        }
        $this->timers[$uid] = $this->scheduler->after($this->timeout, function () use ($uid): void {
            $this->remove($uid);
        });

        return $new;
    }

    public function remove(string $uid): void
    {
        if (! isset($this->timers[$uid])) {
            return;
        }
        $this->scheduler->cancel($this->timers[$uid]);
        unset($this->timers[$uid]);
        ($this->onGone)($uid);
    }

    /**
     * @return list<string>
     */
    public function uids(): array
    {
        return array_map('strval', array_keys($this->timers));
    }

    public function count(): int
    {
        return count($this->timers);
    }

    /**
     * Forgets every server, without telling anyone.
     */
    public function clear(): void
    {
        foreach ($this->timers as $timer) {
            $this->scheduler->cancel($timer);
        }
        $this->timers = [];
    }
}
