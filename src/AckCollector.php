<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;

/**
 * Collects the answers to one broadcast with acknowledgement, as the reference's BroadcastOperator does.
 *
 * Each server reports how many sockets it reached; the callback runs once: with null and every answer when every
 * server reported and all those sockets answered, or with a TimeoutException and the answers so far when the
 * timeout fires first.
 *
 * @internal
 */
final class AckCollector
{
    /** @var list<mixed> */
    private array $responses = [];

    private int $expected = 0;

    private int $reported = 0;

    private bool $done = false;

    private readonly int $timer;

    /**
     * @param Closure(?TimeoutException, list<mixed>): void $ack
     * @param Closure(): void $onTimeout stops the sockets from waiting for their answers
     * @param int $servers how many servers will report their count
     */
    public function __construct(
        private readonly Closure $ack,
        private readonly Scheduler $scheduler,
        int $timeout,
        private readonly Closure $onTimeout,
        private readonly int $servers = 1,
    ) {
        $this->timer = $scheduler->after($timeout, function (): void {
            $this->done = true;
            ($this->onTimeout)();
            ($this->ack)(new TimeoutException('operation has timed out'), $this->responses);
        });
    }

    /**
     * How many sockets one server reached, so how many more answers to wait for.
     */
    public function expect(int $clientCount): void
    {
        $this->expected += $clientCount;
        ++$this->reported;
        $this->complete();
    }

    /**
     * One socket's answer; only its first argument counts, as in the reference.
     */
    public function answer(mixed ...$args): void
    {
        $this->responses[] = $args[0] ?? null;
        $this->complete();
    }

    private function complete(): void
    {
        if ($this->done || $this->reported < $this->servers || $this->expected !== count($this->responses)) {
            return;
        }
        $this->done = true;
        $this->scheduler->cancel($this->timer);
        ($this->ack)(null, $this->responses);
    }
}
