<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

/**
 * One TCP connection opened by a Dialer.
 */
interface Link
{
    public function send(string $bytes): void;

    public function close(): void;
}
