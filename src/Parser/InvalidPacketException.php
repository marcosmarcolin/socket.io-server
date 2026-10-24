<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Parser;

use RuntimeException;

/**
 * A received packet breaks the protocol; the connection must be closed.
 */
final class InvalidPacketException extends RuntimeException
{
}
