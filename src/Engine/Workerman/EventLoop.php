<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use TypeError;
use Workerman\Worker;

/**
 * Whether Workerman's event loop exists yet, for the clients that connect from inside it.
 */
final class EventLoop
{
    public static function runs(): bool
    {
        try {
            Worker::getEventLoop();

            return true;
        } catch (TypeError) {
            // Workerman declares a non-null return, and fails this way before the loop exists.
            return false;
        }
    }
}
