<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Exception;
use Throwable;

/**
 * Refuses a connection from a middleware. The client gets CONNECT_ERROR with the message and the data.
 *
 * Any other Throwable passed to next() is sent with its message only.
 */
final class ConnectionError extends Exception
{
    public function __construct(string $message, public readonly mixed $data = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
