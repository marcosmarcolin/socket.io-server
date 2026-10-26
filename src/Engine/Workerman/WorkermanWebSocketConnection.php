<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Protocol\WebSocket;
use Marcolin\SocketIO\Engine\Transport\WebSocketConnection;
use Workerman\Connection\TcpConnection;

/**
 * A Workerman connection that completed the WebSocket handshake.
 */
final class WorkermanWebSocketConnection implements WebSocketConnection
{
    /** @var list<Closure(string, bool): void> */
    private array $messageListeners = [];

    /** @var list<Closure(): void> */
    private array $closeListeners = [];

    private bool $closed = false;

    public function __construct(private readonly TcpConnection $connection)
    {
    }

    public function send(string $data, bool $binary, bool $compress = false): void
    {
        WebSocket::send($this->connection, $data, $binary, $compress);
    }

    /**
     * Sends a normal-closure (1000) close frame, then closes the TCP connection.
     */
    public function close(): void
    {
        $this->connection->close("\x88\x02\x03\xe8", true);
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
     * Delivers a message from the worker's onMessage.
     */
    public function receive(string $data, bool $binary): void
    {
        foreach ($this->messageListeners as $listener) {
            $listener($data, $binary);
        }
    }

    /**
     * Reports the close from the worker's onClose, once.
     */
    public function closed(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }
}
