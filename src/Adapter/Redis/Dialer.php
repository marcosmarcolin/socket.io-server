<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;

/**
 * Opens TCP connections, faked in tests.
 */
interface Dialer
{
    /**
     * Starts connecting. onClose runs once, after a failure or a close from either side, and may run before
     * dial() returns.
     *
     * @param Closure(): void $onOpen
     * @param Closure(string): void $onData
     * @param Closure(string): void $onError a failure to connect or to send, before any onClose
     * @param Closure(): void $onClose
     */
    public function dial(
        string $host,
        int $port,
        Closure $onOpen,
        Closure $onData,
        Closure $onError,
        Closure $onClose,
    ): Link;
}
