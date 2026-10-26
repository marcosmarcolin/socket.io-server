<?php

/**
 * Picks Workerman's event loop from the LOOP environment variable (select, event or swoole), so every server under
 * tests/ and examples/ runs on it unchanged. tests/php-server.sh loads it with auto_prepend_file.
 */

declare(strict_types=1);

use Workerman\Events\Event;
use Workerman\Events\Select;
use Workerman\Events\Swoole;
use Workerman\Worker;

require __DIR__ . '/../vendor/autoload.php';

Worker::$eventLoopClass = match (getenv('LOOP')) {
    'select' => Select::class,
    'event' => Event::class,
    'swoole' => Swoole::class,
    default => throw new InvalidArgumentException('LOOP must be select, event or swoole'),
};
