<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use RuntimeException;

/**
 * A transport failure, such as an overlapping poll or a connection closed by the client.
 */
final class TransportException extends RuntimeException
{
}
