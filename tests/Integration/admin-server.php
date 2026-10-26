<?php

/**
 * The server of tests/Integration/client/admin.test.js, on port 3000, instrumented for the Admin UI with the
 * login admin / secret.
 *
 * Run by tests/Integration/run.sh admin: php tests/Integration/admin-server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Admin\AdminUi;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(options: [
    'pingInterval' => 1000,
    'pingTimeout' => 1000,
    'cors' => ['origin' => ['https://admin.socket.io'], 'credentials' => true],
]);

$io->on('connection', static function (Socket $socket): void {
    $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
        $socket->join($room);
        $ack();
    });
    $socket->on('ping-me', static function () use ($socket): void {
        $socket->emit('pong-you', 'hi');
    });
});

// Dual-stack: the client connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-admin';
$io->attach($worker);

AdminUi::instrument($io, [
    'auth' => ['type' => 'basic', 'username' => 'admin', 'password' => password_hash('secret', PASSWORD_BCRYPT)],
]);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-admin.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-admin.log';
Worker::runAll();
