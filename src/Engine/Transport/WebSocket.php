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
    /**
     * @param int|null $deflateThreshold deflate messages of at least this many bytes, when the connection
     *        negotiated permessage-deflate; null never does
     */
    public function __construct(
        private readonly WebSocketConnection $connection,
        private readonly ?int $deflateThreshold = null,
    ) {
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
            $data = Parser::encodePacket($packet);
            $compress = $this->deflateThreshold !== null
                && $packet->compress
                && strlen($data) >= $this->deflateThreshold;
            $this->connection->send($data, $packet->binary, $compress);
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
