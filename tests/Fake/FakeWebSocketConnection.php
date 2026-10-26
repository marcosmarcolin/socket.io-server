<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Engine\Transport\WebSocketConnection;

/**
 * A WebSocket connection that records sent frames. Like Workerman, close() reports the close right away.
 */
final class FakeWebSocketConnection implements WebSocketConnection
{
    /** @var list<array{data: string, binary: bool}> */
    public array $sent = [];

    public bool $closed = false;

    /** @var list<Closure(string, bool): void> */
    private array $messageListeners = [];

    /** @var list<Closure(): void> */
    private array $closeListeners = [];

    /** @var list<bool> whether each frame sent was to be deflated */
    public array $compressed = [];

    public function send(string $data, bool $binary, bool $compress = false): void
    {
        $this->sent[] = ['data' => $data, 'binary' => $binary];
        $this->compressed[] = $compress;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }

    public function onMessage(Closure $listener): void
    {
        $this->messageListeners[] = $listener;
    }

    public function onClose(Closure $listener): void
    {
        $this->closeListeners[] = $listener;
    }

    /**
     * Simulates a frame from the client.
     */
    public function receive(string $data, bool $binary = false): void
    {
        foreach ($this->messageListeners as $listener) {
            $listener($data, $binary);
        }
    }

    /**
     * The payload of each sent frame, in order.
     *
     * @return list<string>
     */
    public function sentData(): array
    {
        return array_map(static fn (array $frame): string => $frame['data'], $this->sent);
    }
}
