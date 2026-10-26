<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use Closure;

/**
 * An established WebSocket connection.
 */
interface WebSocketConnection
{
    /**
     * Sends one frame, text or binary; with $compress, deflated, which only a connection that negotiated
     * permessage-deflate may do.
     */
    public function send(string $data, bool $binary, bool $compress = false): void;

    public function close(): void;

    /**
     * @param Closure(string $data, bool $binary): void $listener called for each frame received
     */
    public function onMessage(Closure $listener): void;

    /**
     * @param Closure(): void $listener called once the connection is closed, by either side
     */
    public function onClose(Closure $listener): void;
}
