<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * 15 random bytes in URL-safe base64: 20 characters, like the reference's base64id.
 */
final class RandomSessionIdGenerator implements SessionIdGenerator
{
    public function generate(): string
    {
        return strtr(base64_encode(random_bytes(15)), '+/', '-_');
    }
}
