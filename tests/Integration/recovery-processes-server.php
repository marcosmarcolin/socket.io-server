<?php

/**
 * Two processes with connection state recovery, one per port (3000 and 3001), joined by a workerman/channel server,
 * as behind a proxy with sticky sessions: for tests/Integration/client/recovery-processes.test.js.
 *
 * Run by tests/Integration/run.sh recovery-processes: php tests/Integration/recovery-processes-server.php start
 */

declare(strict_types=1);

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

new ChannelServer('127.0.0.1', 2206);

foreach ([3000, 3001] as $port) {
    $io = new SocketIO(options: [
        'pingInterval' => 1000,
        'pingTimeout' => 1000,
        'connectionStateRecovery' => ['maxDisconnectionDuration' => 10_000],
    ]);
    // The broker connection needs the process's event loop.
    $io->on('workerStart', static function () use ($io): void {
        $io->adapter(ChannelAdapter::factory('127.0.0.1', 2206));
    });
    $io->on('connection', static function (Socket $socket) use ($io, $port): void {
        $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
            $socket->join($room);
            $ack();
        });
        $socket->on('to-room', static function (string $room, string $message, Closure $ack) use ($io): void {
            $io->to($room)->emit('message', $message);
            $ack();
        });
        // Whether a socket is connected to this process.
        $socket->on('is-connected', static function (string $id, Closure $ack) use ($io): void {
            $ack(isset($io->sockets->sockets()[$id]));
        });
        $socket->on('state', static function (Closure $ack) use ($socket, $port): void {
            $ack(['port' => $port, 'recovered' => $socket->recovered, 'rooms' => $socket->rooms]);
        });
    });

    // Dual-stack: the client connects to "localhost", which may resolve to ::1.
    $worker = new Worker('tcp://[::]:' . $port);
    $worker->name = 'socket-io-recovery-' . $port;
    $io->attach($worker);
}

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-recovery-processes.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-recovery-processes.log';
Worker::runAll();
