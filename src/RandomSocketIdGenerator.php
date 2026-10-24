<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

final class RandomSocketIdGenerator implements SocketIdGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(12));
    }
}
