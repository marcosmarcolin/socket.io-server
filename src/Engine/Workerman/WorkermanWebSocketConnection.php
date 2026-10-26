<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Protocol\WebSocket;
use Marcolin\SocketIO\Engine\Transport\WebSocketConnection;
use Workerman\Connection\TcpConnection;

/**
 * A Workerman connection that completed the WebSocket handshake.
 *
 * The first frame of a pass of the event loop goes out at once, as a lone message should; the frames that follow
 * it in the same pass are grouped and go out in one write at the pass's end, through the WriteGroup's timer. A burst
 * of messages to a client then costs two system calls instead of one per message. Workerman runs timers after the
 * sockets of the pass, so nothing waits for another pass under select() and event.
 */
final class WorkermanWebSocketConnection implements WebSocketConnection
{
    /** The frames grouped since the last flush, ready to send as they are. */
    private string $pending = '';

    // Whether a frame went out at once in this pass, so the next ones are grouped.
    private bool $wrote = false;

    /** @var list<Closure(string, bool): void> */
    private array $messageListeners = [];

    /** @var list<Closure(): void> */
    private array $closeListeners = [];

    private bool $closed = false;

    public function __construct(private readonly TcpConnection $connection, private readonly WriteGroup $group)
    {
    }

    public function send(string $data, bool $binary, bool $compress = false): void
    {
        // Workerman's deflate keeps a context across frames, so what is grouped goes first, in order.
        if ($compress) {
            $this->flush();
            WebSocket::send($this->connection, $data, $binary, true);

            return;
        }
        if (! $this->wrote && $this->pending === '') {
            $this->wrote = true;
            $this->group->add($this);
            $this->connection->send(self::frame($data, $binary), true);

            return;
        }
        $this->pending .= self::frame($data, $binary);
    }

    /**
     * The pass of the event loop ended: what was grouped goes out, and the next frame goes out at once again.
     */
    public function endPass(): void
    {
        $this->wrote = false;
        $this->flush();
    }

    /**
     * Writes the grouped frames now.
     */
    public function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        $frames = $this->pending;
        $this->pending = '';
        $this->connection->send($frames, true);
    }

    /**
     * Sends a normal-closure (1000) close frame after what is grouped, then closes the TCP connection.
     */
    public function close(): void
    {
        $this->flush();
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
        $this->group->remove($this);
        $this->pending = '';
        foreach ($this->closeListeners as $listener) {
            $listener();
        }
    }

    /**
     * A final, unmasked frame, as a server sends it (RFC 6455, section 5.2), as Workerman's Websocket::encode()
     * builds it.
     */
    private static function frame(string $data, bool $binary): string
    {
        $head = $binary ? "\x82" : "\x81";
        $length = strlen($data);

        return match (true) {
            $length <= 125 => $head . chr($length) . $data,
            $length <= 65535 => $head . chr(126) . pack('n', $length) . $data,
            default => $head . chr(127) . pack('J', $length) . $data,
        };
    }
}
