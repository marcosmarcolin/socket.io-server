<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Cluster;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\TimeoutException;

/**
 * A request to the other servers, waiting for one response from each.
 *
 * The callback runs once: with null and the responses when every server answered or left, or with a
 * TimeoutException and the responses so far.
 *
 * @internal
 */
final class Request
{
    /** @var array<string, true> the servers yet to answer */
    private array $missing = [];

    /** @var list<mixed> */
    private array $responses = [];

    private bool $done = false;

    private readonly int $timer;

    /**
     * @param list<string> $uids the servers asked
     * @param Closure(?TimeoutException, list<mixed>): void $callback
     * @param Closure(): void $onDone
     */
    public function __construct(
        array $uids,
        private readonly Scheduler $scheduler,
        int $timeout,
        private readonly Closure $callback,
        private readonly Closure $onDone,
    ) {
        foreach ($uids as $uid) {
            $this->missing[$uid] = true;
        }
        $this->timer = $scheduler->after($timeout, function (): void {
            $missing = count($this->missing);
            $this->finish(new TimeoutException(sprintf('timeout reached: missing %d responses', $missing)));
        });
    }

    /**
     * One server's response; a server that wasn't asked, or already answered, is ignored.
     */
    public function respond(string $uid, mixed $response): void
    {
        if ($this->done || ! isset($this->missing[$uid])) {
            return;
        }
        unset($this->missing[$uid]);
        $this->responses[] = $response;
        $this->completeIfAnswered();
    }

    /**
     * A server left the cluster: no response will come from it.
     */
    public function gone(string $uid): void
    {
        if ($this->done || ! isset($this->missing[$uid])) {
            return;
        }
        unset($this->missing[$uid]);
        $this->completeIfAnswered();
    }

    private function completeIfAnswered(): void
    {
        if ($this->missing === []) {
            $this->scheduler->cancel($this->timer);
            $this->finish(null);
        }
    }

    private function finish(?TimeoutException $error): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        ($this->onDone)();
        ($this->callback)($error, $this->responses);
    }
}
