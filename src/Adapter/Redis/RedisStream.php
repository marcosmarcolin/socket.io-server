<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Marcolin\SocketIO\Adapter\Stream;
use Marcolin\SocketIO\Engine\Scheduler;
use RuntimeException;
use Throwable;

/**
 * A Stream on a Redis stream, as the reference's redis-streams-adapter: one stream for every namespace, trimmed to
 * about maxLen entries, each with the fields "nsp" and "message" (serialized as RedisPubSub does).
 *
 * Appends and sessions go through the commands connection, which may be shared with RedisPubSub. A second
 * connection only reads, with a blocking XREAD from the last entry it got, so after an outage it reads what was
 * appended meanwhile. It reads while some namespace listens.
 *
 * Sessions are strings under "<sessionKeyPrefix><pid>", which expire on their own (SET with PX) and are taken once
 * (GETDEL, Redis 6.2).
 */
final class RedisStream implements Stream
{
    public const RETRY_DELAY = 1000;

    // At most this many XRANGE calls for one restore, as the reference, since the stream may grow while it reads.
    public const MAX_RESTORE_READS = 100;

    /** @var array<string, Closure(array<mixed>, string): void> */
    private array $listeners = [];

    private bool $reading = false;

    private ?string $lastId = null;

    private readonly RedisConnection $reader;

    /**
     * @param RedisConnection $commands opened by its owner
     * @param Closure(Throwable): void $onError
     */
    public function __construct(
        private readonly RedisConnection $commands,
        RedisUrl $url,
        Dialer $dialer,
        private readonly Scheduler $scheduler,
        private readonly Closure $onError,
        private readonly RedisStreamOptions $options,
    ) {
        $this->reader = new RedisConnection($url, $dialer, $scheduler, $this->read(...), static function (): void {
        }, $onError);
        $this->reader->open();
    }

    public function append(string $nsp, array $message, Closure $appended): void
    {
        $this->commands->call(
            static function (mixed $reply) use ($appended): void {
                $appended(is_string($reply) ? $reply : null);
            },
            ...self::appendCommand($this->options, $nsp, $message),
        );
    }

    /**
     * The XADD of a message, shared with the Emitter.
     *
     * @param array<string, mixed> $message
     * @return list<string>
     */
    public static function appendCommand(RedisStreamOptions $options, string $nsp, array $message): array
    {
        return [
            'XADD',
            $options->streamName,
            'MAXLEN',
            '~',
            (string) $options->maxLen,
            '*',
            'nsp',
            $nsp,
            'message',
            serialize($message),
        ];
    }

    public function subscribe(string $nsp, Closure $listener): void
    {
        $this->listeners[$nsp] = $listener;
        $this->read();
    }

    public function unsubscribe(string $nsp): void
    {
        unset($this->listeners[$nsp]);
    }

    public function saveSession(string $pid, string $session, int $ttl): void
    {
        $this->commands->send('SET', $this->options->sessionKeyPrefix . $pid, $session, 'PX', (string) $ttl);
    }

    public function restoreSession(string $nsp, string $pid, string $offset, Closure $callback): void
    {
        if (preg_match('/^\d{1,20}-\d{1,20}$/', $offset) !== 1) {
            $callback(null, []);

            return;
        }
        $session = null;
        $this->commands->call(static function (mixed $reply) use (&$session): void {
            $session = is_string($reply) ? $reply : null;
        }, 'GETDEL', $this->options->sessionKeyPrefix . $pid);
        // The offset's own entry comes first: without it, the offset was trimmed, and events may be missing.
        $this->commands->call(function (mixed $reply) use (&$session, $nsp, $offset, $callback): void {
            $entries = self::entries($reply);
            if ($session === null || $entries === null || ($entries[0][2] ?? null) !== $offset) {
                $callback(null, []);

                return;
            }
            $this->readAfter($nsp, $offset, [], 1, function (array $missed) use (&$session, $callback): void {
                $callback($session, $missed);
            });
        }, 'XRANGE', $this->options->streamName, $offset, '+', 'COUNT', '1');
    }

    /**
     * Reads the entries after the offset, a batch at a time.
     *
     * @param list<array{array<mixed>, string}> $missed
     * @param Closure(list<array{array<mixed>, string}>): void $done
     */
    private function readAfter(string $nsp, string $offset, array $missed, int $reads, Closure $done): void
    {
        $this->commands->call(function (mixed $reply) use ($nsp, $missed, $reads, $done): void {
            $entries = self::entries($reply) ?? [];
            $last = null;
            foreach ($entries as [$entryNsp, $message, $id]) {
                $last = $id;
                if ($entryNsp === $nsp && $message !== null) {
                    $missed[] = [$message, $id];
                }
            }
            if ($last === null || count($entries) < $this->options->readCount || $reads >= self::MAX_RESTORE_READS) {
                $done($missed);

                return;
            }
            $this->readAfter($nsp, $last, $missed, $reads + 1, $done);
        }, 'XRANGE', $this->options->streamName, '(' . $offset, '+', 'COUNT', (string) $this->options->readCount);
    }

    /**
     * Starts the blocking read, unless it runs, nobody listens, or the reader isn't ready: its login starts it.
     */
    private function read(): void
    {
        if ($this->reading || $this->listeners === [] || ! $this->reader->isReady()) {
            return;
        }
        $this->reading = true;
        $this->reader->call(
            $this->onRead(...),
            'XREAD',
            'COUNT',
            (string) $this->options->readCount,
            'BLOCK',
            (string) $this->options->blockTimeInMs,
            'STREAMS',
            $this->options->streamName,
            $this->lastId ?? '$',
        );
    }

    private function onRead(mixed $reply): void
    {
        $this->reading = false;
        if ($reply instanceof ConnectionLost) {
            // The reader reads again once it logged in again.
            return;
        }
        if ($reply instanceof RedisError) {
            $this->scheduler->after(self::RETRY_DELAY, $this->read(...));

            return;
        }
        // A reply is [[stream, entries]], or null when the block timed out.
        $streams = is_array($reply) ? $reply : [];
        $entries = is_array($streams[0] ?? null) ? self::entries($streams[0][1] ?? null) : [];
        if ($entries === null) {
            ($this->onError)(new RuntimeException('Malformed XREAD reply from Redis'));
            $entries = [];
        }
        foreach ($entries as [$nsp, $message, $id]) {
            $this->lastId = $id;
            $listener = $nsp === null ? null : ($this->listeners[$nsp] ?? null);
            if ($listener === null || $message === null) {
                continue;
            }
            try {
                $listener($message, $id);
            } catch (Throwable $error) {
                ($this->onError)($error);
            }
        }
        $this->read();
    }

    /**
     * The entries of an XRANGE or XREAD reply, as their namespace, message (null when malformed) and id; null for
     * a reply that isn't a list of entries.
     *
     * @return list<array{?string, ?array<mixed>, string}>|null
     */
    private static function entries(mixed $reply): ?array
    {
        if (! is_array($reply)) {
            return null;
        }
        $entries = [];
        foreach ($reply as $entry) {
            $id = is_array($entry) ? ($entry[0] ?? null) : null;
            $fields = is_array($entry) ? ($entry[1] ?? null) : null;
            if (! is_string($id) || ! is_array($fields)) {
                return null;
            }
            $values = [];
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                if (is_string($fields[$i]) && is_string($fields[$i + 1])) {
                    $values[$fields[$i]] = $fields[$i + 1];
                }
            }
            $message = isset($values['message']) ? RedisPubSub::unserialize($values['message']) : null;
            $entries[] = [$values['nsp'] ?? null, $message, $id];
        }

        return $entries;
    }
}
