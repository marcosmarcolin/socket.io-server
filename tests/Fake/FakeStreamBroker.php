<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use RuntimeException;

/**
 * A Redis stream in memory, for several fake processes, with ids "<n>-0": each process reads every entry once, in
 * order, while it is online; one taken offline reads what it missed when it comes back. hold() keeps appends waiting
 * for their id, as Redis does until it answers. Messages go through serialize(), and sessions expire on the clock.
 */
final class FakeStreamBroker
{
    /** @var list<array{string, string, array<mixed>}> id, namespace and message of each entry */
    public array $entries = [];

    /** @var array<string, array<string, Closure(array<mixed>, string): void>> listeners by process, then namespace */
    private array $listeners = [];

    /** @var array<string, int> how many entries each process read */
    private array $read = [];

    /** @var array<string, true> */
    private array $offline = [];

    /** @var array<string, array{string, int}> sessions by pid, with their expiry */
    private array $sessions = [];

    private bool $holding = false;

    /** @var list<array{string, array<string, mixed>, Closure(?string): void}> */
    private array $held = [];

    public bool $failAppends = false;

    // Keeps the next restores until finishRestores(), as Redis answers later.
    public bool $deferRestores = false;

    /** @var list<Closure(): void> */
    private array $deferred = [];

    public function __construct(private readonly FakeScheduler $clock)
    {
    }

    public function connect(string $process): FakeStream
    {
        $this->read[$process] = count($this->entries);

        return new FakeStream($this, $process);
    }

    /**
     * @param array<string, mixed> $message
     * @param Closure(?string): void $appended
     */
    public function append(string $nsp, array $message, Closure $appended): void
    {
        if ($this->holding) {
            $this->held[] = [$nsp, $message, $appended];

            return;
        }
        if ($this->failAppends) {
            $appended(null);

            return;
        }
        // No class may come back: a message with objects would not survive.
        $copy = unserialize(serialize($message), ['allowed_classes' => false]);
        if ($copy != $message || ! is_array($copy)) {
            throw new RuntimeException('The message does not survive serialization without classes');
        }
        $id = (count($this->entries) + 1) . '-0';
        $this->entries[] = [$id, $nsp, $copy];
        $appended($id);
        $this->deliver();
    }

    public function hold(): void
    {
        $this->holding = true;
    }

    /**
     * Gives the held appends their ids, in order.
     */
    public function release(): void
    {
        $this->holding = false;
        $held = $this->held;
        $this->held = [];
        foreach ($held as [$nsp, $message, $appended]) {
            $this->append($nsp, $message, $appended);
        }
    }

    public function goOffline(string $process): void
    {
        $this->offline[$process] = true;
    }

    public function comeBack(string $process): void
    {
        unset($this->offline[$process]);
        $this->deliver();
    }

    /**
     * @param Closure(array<mixed>, string): void $listener
     */
    public function subscribe(string $process, string $nsp, Closure $listener): void
    {
        $this->listeners[$process][$nsp] = $listener;
    }

    public function unsubscribe(string $process, string $nsp): void
    {
        unset($this->listeners[$process][$nsp]);
    }

    public function saveSession(string $pid, string $session, int $ttl): void
    {
        $this->sessions[$pid] = [$session, $this->clock->now() + $ttl];
    }

    /**
     * @param Closure(?string, list<array{array<mixed>, string}>): void $callback
     */
    public function restoreSession(string $nsp, string $pid, string $offset, Closure $callback): void
    {
        if ($this->deferRestores) {
            $this->deferred[] = function () use ($nsp, $pid, $offset, $callback): void {
                $this->restoreSession($nsp, $pid, $offset, $callback);
            };

            return;
        }
        [$session, $expiry] = $this->sessions[$pid] ?? [null, 0];
        unset($this->sessions[$pid]);
        $index = array_search($offset, array_column($this->entries, 0), true);
        if ($session === null || $expiry < $this->clock->now() || $index === false) {
            $callback(null, []);

            return;
        }
        $missed = [];
        foreach (array_slice($this->entries, $index + 1) as [$id, $entryNsp, $message]) {
            if ($entryNsp === $nsp) {
                $missed[] = [$message, $id];
            }
        }
        $callback($session, $missed);
    }

    public function finishRestores(): void
    {
        $this->deferRestores = false;
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $restore) {
            $restore();
        }
    }

    /**
     * Keeps only the last entries, as MAXLEN does.
     */
    public function trim(int $keep): void
    {
        $dropped = max(0, count($this->entries) - $keep);
        $this->entries = array_slice($this->entries, $dropped);
        foreach ($this->read as $process => $read) {
            $this->read[$process] = max(0, $read - $dropped);
        }
    }

    private function deliver(): void
    {
        foreach ($this->read as $process => $read) {
            while (! isset($this->offline[$process]) && $this->read[$process] < count($this->entries)) {
                [$id, $nsp, $message] = $this->entries[$this->read[$process]++];
                $listener = $this->listeners[$process][$nsp] ?? null;
                if ($listener !== null) {
                    $listener($message, $id);
                }
            }
        }
    }
}
