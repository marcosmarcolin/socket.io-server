<?php

/**
 * The server of tests/Integration/client/recovery.test.js, on port 3000, with connection state recovery.
 *
 * Run by tests/Integration/run.sh recovery: php tests/Integration/recovery-server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(options: [
    'pingInterval' => 1000,
    'pingTimeout' => 1000,
    'connectionStateRecovery' => ['maxDisconnectionDuration' => 10_000],
]);

// How many times the middleware let each socket id in.
$checks = [];
$io->use(static function (Socket $socket, Closure $next) use (&$checks): void {
    $checks[$socket->id] = ($checks[$socket->id] ?? 0) + 1;
    $next();
});

$io->on('connection', static function (Socket $socket) use ($io, &$checks): void {
    $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
        $socket->join($room);
        $ack();
    });
    $socket->on('set-data', static function (array $data, Closure $ack) use ($socket): void {
        $socket->data = $data;
        $ack();
    });
    $socket->on('state', static function (Closure $ack) use ($socket, &$checks): void {
        $ack([
            'id' => $socket->id,
            'recovered' => $socket->recovered,
            'rooms' => $socket->rooms,
            'data' => $socket->data,
            'checks' => $checks[$socket->id] ?? 0,
        ]);
    });
    $socket->on('to-room', static function (string $room, string $message, Closure $ack) use ($io): void {
        $io->to($room)->emit('message', $message);
        $ack();
    });
    $socket->on('is-connected', static function (string $id, Closure $ack) use ($io): void {
        $ack(isset($io->sockets->sockets()[$id]));
    });
});

// Dual-stack: the client connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-recovery';
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-recovery.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-recovery.log';
Worker::runAll();
