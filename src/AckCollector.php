<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;

/**
 * Collects the answers to one broadcast with acknowledgement, as the reference's BroadcastOperator does.
 *
 * The callback runs once: with null and every answer when all expected answers arrived, or with a
 * TimeoutException and the answers so far when the timeout fires first.
 *
 * @internal
 */
final class AckCollector
{
    /** @var list<mixed> */
    private array $responses = [];

    private ?int $expected = null;

    private bool $done = false;

    private readonly int $timer;

    /**
     * @param Closure(?TimeoutException, list<mixed>): void $ack
     * @param Closure(): void $onTimeout stops the sockets from waiting for their answers
     */
    public function __construct(
        private readonly Closure $ack,
        private readonly Scheduler $scheduler,
        int $timeout,
        private readonly Closure $onTimeout,
    ) {
        $this->timer = $scheduler->after($timeout, function (): void {
            $this->done = true;
            ($this->onTimeout)();
            ($this->ack)(new TimeoutException('operation has timed out'), $this->responses);
        });
    }

    /**
     * How many sockets the broadcast reached, so how many answers to wait for.
     */
    public function expect(int $clientCount): void
    {
        $this->expected = $clientCount;
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
        if ($this->done || $this->expected !== count($this->responses)) {
            return;
        }
        $this->done = true;
        $this->scheduler->cancel($this->timer);
        ($this->ack)(null, $this->responses);
    }
}
