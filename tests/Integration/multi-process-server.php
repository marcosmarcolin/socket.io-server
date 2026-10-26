<?php

/**
 * Two worker processes on port 3000, joined by a workerman/channel server, or by Redis with ADAPTER=redis and
 * REDIS_URL, for tests/Integration/client/multi-process.test.js.
 *
 * Run by tests/Integration/run.sh multi-process and multi-process-redis:
 * php tests/Integration/multi-process-server.php start
 */

declare(strict_types=1);

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

const PROCESSES = 2;

$redisUrl = getenv('ADAPTER') === 'redis' ? (string) getenv('REDIS_URL') : null;
if ($redisUrl === null) {
    new ChannelServer('127.0.0.1', 2206);
}

$io = new SocketIO(options: ['pingInterval' => 5000, 'pingTimeout' => 5000]);

// The broker connection needs each process's event loop, so the adapter is set when the process starts.
$io->on('workerStart', static function () use ($io, $redisUrl): void {
    $io->adapter(
        $redisUrl === null ? ChannelAdapter::factory('127.0.0.1', 2206) : RedisAdapter::factory($redisUrl),
    );
});

$io->on('connection', static function (Socket $socket) use ($io): void {
    $socket->on('whoami', static function (Closure $ack): void {
        $ack(getmypid());
    });
    $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
        $socket->join($room);
        $ack(getmypid());
    });
    $socket->on('everyone', static function (string $message) use ($io): void {
        $io->emit('everyone', $message);
    });
    $socket->on('to-rooms', static function (array $rooms, string $message) use ($io): void {
        $io->to(array_values(array_filter($rooms, 'is_string')))->emit('room-message', $message);
    });
    $socket->on('everyone-except', static function (string $room, string $message) use ($io): void {
        $io->except($room)->emit('except-message', $message);
    });
    $socket->on('broadcast', static function (string $message) use ($socket): void {
        $socket->broadcast->emit('broadcast-message', $message);
    });
    // Sends numbered events to every client, for the scale check.
    $socket->on('flood', static function (int $count) use ($io): void {
        for ($i = 0; $i < $count; $i++) {
            $io->emit('flood', $i);
        }
    });
});

// Dual-stack: the client connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-multi-process';
$worker->count = PROCESSES;
// Lets the kernel spread connections over the processes.
$worker->reusePort = true;
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-multi-process.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-multi-process.log';
Worker::runAll();
