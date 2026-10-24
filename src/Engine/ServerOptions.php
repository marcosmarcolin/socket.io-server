<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use InvalidArgumentException;

/**
 * Engine.IO options. Times are in milliseconds, sizes in bytes.
 */
final class ServerOptions
{
    public function __construct(
        public readonly int $pingInterval = 25_000,
        public readonly int $pingTimeout = 20_000,
        public readonly int $maxPayload = 1_000_000,
        public readonly int $upgradeTimeout = 10_000,
    ) {
        self::assertPositive('pingInterval', $pingInterval);
        self::assertPositive('pingTimeout', $pingTimeout);
        self::assertPositive('maxPayload', $maxPayload);
        self::assertPositive('upgradeTimeout', $upgradeTimeout);
    }

    private static function assertPositive(string $name, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be positive, got %d', $name, $value));
        }
    }
}
