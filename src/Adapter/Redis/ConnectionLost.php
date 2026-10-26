<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use RuntimeException;

/**
 * The connection to Redis failed mid-command.
 *
 * @internal
 */
final class ConnectionLost extends RuntimeException
{
}
