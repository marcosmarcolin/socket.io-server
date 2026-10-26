<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;

/**
 * The debug log of the `logger` option, in the reference's `debug` scopes: `engine`, `engine:socket`,
 * `socket.io:client`, `socket.io:socket` and `socket.io:adapter`. Without the option, nothing is logged.
 *
 * @internal
 */
final class Logger
{
    /**
     * @param (Closure(string, string): void)|null $sink gets the scope and the message
     */
    public function __construct(private readonly ?Closure $sink = null)
    {
    }

    public function enabled(): bool
    {
        return $this->sink !== null;
    }

    public function debug(string $scope, string $message): void
    {
        if ($this->sink !== null) {
            ($this->sink)($scope, $message);
        }
    }
}
