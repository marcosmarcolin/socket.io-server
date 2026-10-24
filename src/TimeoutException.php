<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use RuntimeException;

/**
 * Passed to an acknowledgement callback when the answer didn't arrive in time.
 */
final class TimeoutException extends RuntimeException
{
}
