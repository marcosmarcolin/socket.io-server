<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use Closure;
use Marcolin\SocketIO\Engine\InvalidPacketException;
use Marcolin\SocketIO\Engine\Parser;

/**
 * WebSocket: one packet per frame, binary packets as binary frames.
 */
final class WebSocket extends Transport
{
    public function __construct(private readonly WebSocketConnection $connection)
    {
        $connection->onMessage(function (string $data, bool $binary): void {
            try {
                $packet = Parser::decodePacket($data, $binary);
            } catch (InvalidPacketException $e) {
                $this->onError($e);

                return;
            }
            $this->onPacket($packet);
        });
        $connection->onClose(function (): void {
            $this->onClose();
        });
        $this->writable = true;
    }

    public function name(): string
    {
        return 'websocket';
    }

    public function send(array $packets): void
    {
        foreach ($packets as $packet) {
            $this->connection->send(Parser::encodePacket($packet), $packet->binary);
        }
        $this->dispatch('drain');
    }

    protected function doClose(Closure $onClosed): void
    {
        // Workerman may report the close synchronously; the caller's callback must run first.
        $onClosed();
        $this->connection->close();
    }
}
