<?php

/**
 * A process outside the servers, as a PHP-FPM request would be: emits to a room through Redis with the Emitter.
 *
 * Run by multi-process-server.php: php tests/Integration/emitter.php <room> <message>
 */

declare(strict_types=1);

use Marcolin\SocketIO\Emitter;

require __DIR__ . '/../../vendor/autoload.php';

[, $room, $message] = $argv + [null, '', ''];
$tls = (string) getenv('REDIS_TLS_CA') === '' ? [] : ['cafile' => (string) getenv('REDIS_TLS_CA')];
Emitter::redis((string) getenv('REDIS_URL'), tls: $tls)
    ->to((string) $room)
    ->emit('from-emitter', (string) $message, getmypid());
