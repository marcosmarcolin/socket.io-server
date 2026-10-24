<?php

/**
 * The server the official Socket.IO v5 test suite expects, as the spec describes it, on port 3000.
 *
 * Run by tests/Compliance/socket-io.sh: php tests/Compliance/socket-io-server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

// The options from the spec's "Test suite" section.
$io = new SocketIO(options: [
    'pingInterval' => 300,
    'pingTimeout' => 200,
    'maxPayload' => 1_000_000,
    'connectTimeout' => 1000,
    'cors' => ['origin' => '*'],
]);

$io->on('connection', static function (Socket $socket): void {
    // JSON objects arrive as arrays; the cast sends the auth payload back as an object, even when empty.
    $socket->emit('auth', (object) $socket->handshake['auth']);
    $socket->on('message', static function (mixed ...$args) use ($socket): void {
        $socket->emit('message-back', ...$args);
    });
    $socket->on('message-with-ack', static function (mixed ...$args): void {
        $ack = array_pop($args);
        if ($ack instanceof Closure) {
            $ack(...$args);
        }
    });
});

$io->of('/custom')->on('connection', static function (Socket $socket): void {
    $socket->emit('auth', (object) $socket->handshake['auth']);
});

// Dual-stack: the suite connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-compliance';
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-compliance.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-compliance.log';
Worker::runAll();
