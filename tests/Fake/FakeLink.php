<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Adapter\Redis\Link;
use Marcolin\SocketIO\Adapter\Redis\Resp;

/**
 * One fake TCP connection: the test plays the Redis server.
 */
final class FakeLink implements Link
{
    /** @var list<string> */
    public array $sent = [];

    public bool $closed = false;

    /**
     * @param Closure(): void $onOpen
     * @param Closure(string): void $onData
     * @param Closure(string): void $onError
     * @param Closure(): void $onClose
     */
    public function __construct(
        private readonly Closure $onOpen,
        private readonly Closure $onData,
        private readonly Closure $onError,
        private readonly Closure $onClose,
    ) {
    }

    public function send(string $bytes): void
    {
        $this->sent[] = $bytes;
    }

    public function close(): void
    {
        $this->drop();
    }

    public function open(): void
    {
        ($this->onOpen)();
    }

    public function receive(string $bytes): void
    {
        ($this->onData)($bytes);
    }

    public function fail(string $message): void
    {
        ($this->onError)($message);
        $this->drop();
    }

    public function drop(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            ($this->onClose)();
        }
    }

    /**
     * The commands sent, decoded, and forgets them.
     *
     * @return list<list<string>>
     */
    public function commands(): array
    {
        $resp = new Resp();
        $commands = [];
        foreach ($resp->add(implode('', $this->sent)) as $command) {
            $commands[] = array_values(
                array_map(static fn (mixed $part): string => is_string($part) ? $part : '', (array) $command),
            );
        }
        $this->sent = [];

        return $commands;
    }
}
