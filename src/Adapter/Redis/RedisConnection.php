<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Marcolin\SocketIO\Engine\Scheduler;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * One connection to Redis that logs in, reconnects after a second when it drops, and keeps commands until it is
 * ready.
 *
 * While it is not ready, it keeps up to 1000 commands and drops the rest. Errors, Redis error replies and
 * exceptions from onReply and the reply callbacks go to onError; a failure to connect is reported once until the
 * connection is ready again.
 *
 * A command sent with call() gets its own reply, since Redis answers in order: a RedisError for an error reply, or a
 * ConnectionLost when the connection dropped before the reply, or the command was dropped. Only on a connection
 * that doesn't subscribe, whose pushed messages answer no command.
 */
final class RedisConnection
{
    public const RECONNECT_DELAY = 1000;

    public const MAX_QUEUED = 1000;

    private ?Link $link = null;

    // Counts the dial attempts, so callbacks of a closed link are ignored.
    private int $attempt = 0;

    private bool $ready = false;

    private bool $authenticating = false;

    private bool $failureReported = false;

    /** @var list<array{string, (Closure(mixed): void)|null}> commands kept until ready, with their callbacks */
    private array $queue = [];

    /** @var list<(Closure(mixed): void)|null> the callbacks of the commands sent, in order, until their replies */
    private array $callbacks = [];

    private readonly Resp $resp;

    /**
     * @param Closure(): void $onReady after each login, before the queued commands are sent
     * @param Closure(mixed): void $onReply each reply that isn't an error, and pushed messages
     * @param Closure(Throwable): void $onError
     */
    public function __construct(
        private readonly RedisUrl $url,
        private readonly Dialer $dialer,
        private readonly Scheduler $scheduler,
        private readonly Closure $onReady,
        private readonly Closure $onReply,
        private readonly Closure $onError,
    ) {
        $this->resp = new Resp();
    }

    public function open(): void
    {
        $attempt = ++$this->attempt;
        $current = fn (): bool => $attempt === $this->attempt;
        $closed = false;
        $link = $this->dialer->dial(
            $this->url->host,
            $this->url->port,
            function () use ($current): void {
                if ($current()) {
                    $this->opened();
                }
            },
            function (string $bytes) use ($current): void {
                if ($current()) {
                    $this->received($bytes);
                }
            },
            function (string $message) use ($current): void {
                if ($current()) {
                    $this->failed($message);
                }
            },
            function () use ($current, &$closed): void {
                $closed = true;
                if ($current()) {
                    $this->closed();
                }
            },
        );
        if (! $closed) {
            $this->link = $link;
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Sends a command now, or once the connection is ready.
     */
    public function send(string ...$command): void
    {
        $this->write(Resp::command(...$command), null);
    }

    /**
     * Sends a command now, or once the connection is ready, and hands its reply to the callback.
     *
     * @param Closure(mixed): void $onReply
     */
    public function call(Closure $onReply, string ...$command): void
    {
        $this->write(Resp::command(...$command), $onReply);
    }

    /**
     * @param (Closure(mixed): void)|null $onReply
     */
    private function write(string $bytes, ?Closure $onReply): void
    {
        if ($this->ready && $this->link !== null) {
            $this->callbacks[] = $onReply;
            $this->link->send($bytes);
        } elseif (count($this->queue) < self::MAX_QUEUED) {
            $this->queue[] = [$bytes, $onReply];
        } elseif ($onReply !== null) {
            $this->answer($onReply, new ConnectionLost('too many commands waiting for the connection'));
        }
    }

    private function opened(): void
    {
        $auth = $this->url->auth();
        if ($auth === null) {
            $this->becomeReady();

            return;
        }
        $this->authenticating = true;
        $this->link?->send(Resp::command(...$auth));
    }

    private function received(string $bytes): void
    {
        try {
            $replies = $this->resp->add($bytes);
        } catch (UnexpectedValueException $error) {
            ($this->onError)($error);
            $this->link?->close();

            return;
        }
        foreach ($replies as $reply) {
            if ($this->authenticating) {
                $this->authenticated($reply);

                continue;
            }
            if ($reply instanceof RedisError) {
                ($this->onError)(new RuntimeException('Redis error: ' . $reply->message));
            }
            $onReply = array_shift($this->callbacks);
            if ($onReply !== null) {
                $this->answer($onReply, $reply);
            } elseif (! $reply instanceof RedisError) {
                $this->deliver($reply);
            }
        }
    }

    private function authenticated(mixed $reply): void
    {
        $this->authenticating = false;
        if ($reply instanceof RedisError) {
            $this->failed('Redis refused the login: ' . $reply->message);
            $this->link?->close();

            return;
        }
        $this->becomeReady();
    }

    private function becomeReady(): void
    {
        $this->ready = true;
        $this->failureReported = false;
        ($this->onReady)();
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as [$bytes, $onReply]) {
            $this->write($bytes, $onReply);
        }
    }

    private function deliver(mixed $reply): void
    {
        $this->answer($this->onReply, $reply);
    }

    /**
     * @param Closure(mixed): void $onReply
     */
    private function answer(Closure $onReply, mixed $reply): void
    {
        try {
            $onReply($reply);
        } catch (Throwable $error) {
            ($this->onError)($error);
        }
    }

    private function failed(string $message): void
    {
        if ($this->ready) {
            ($this->onError)(new RuntimeException('Redis connection error: ' . $message));
        } elseif (! $this->failureReported) {
            $this->failureReported = true;
            ($this->onError)(new RuntimeException(
                'Cannot connect to Redis at ' . $this->url->host . ':' . $this->url->port . ': ' . $message
                . '; retrying every second',
            ));
        }
    }

    private function closed(): void
    {
        if ($this->ready) {
            $this->failureReported = true;
            ($this->onError)(new RuntimeException('Lost the connection to Redis; reconnecting every second'));
        }
        $this->link = null;
        $this->ready = false;
        $this->authenticating = false;
        $this->resp->reset();
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        $this->scheduler->after(self::RECONNECT_DELAY, $this->open(...));
        foreach ($callbacks as $onReply) {
            if ($onReply !== null) {
                $this->answer($onReply, new ConnectionLost('the connection closed before the reply'));
            }
        }
    }
}
