<?php

/**
 * The server the load test talks to, on port 3000: one process, as a single worker serves.
 *
 * Run by tests/Load/run.sh: php tests/Load/server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(options: ['pingInterval' => 25000, 'pingTimeout' => 20000]);

// The process's resident memory, in bytes, as the kernel counts it.
$rss = static function (): int {
    $status = (string) @file_get_contents('/proc/self/status');

    return preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $match) === 1 ? (int) $match[1] * 1024 : 0;
};

$io->on('connection', static function (Socket $socket) use ($io, $rss): void {
    $socket->on('echo', static function (mixed $data, Closure $ack): void {
        $ack($data);
    });
    $socket->on('stats', static function (Closure $ack) use ($io, $rss): void {
        gc_collect_cycles();
        $ack([
            'heap' => memory_get_usage(),
            'rss' => $rss(),
            'sockets' => count($io->sockets->sockets()),
            'loop' => (new ReflectionClass(Worker::getEventLoop()))->getShortName(),
        ]);
    });
    // Broadcasts numbered messages to every socket, then says how long the emits took.
    $socket->on('flood', static function (int $count, int $size, Closure $ack) use ($io): void {
        $payload = str_repeat('x', $size);
        $start = hrtime(true);
        for ($i = 0; $i < $count; $i++) {
            $io->emit('flood', $i, $payload);
        }
        $ack(intdiv(hrtime(true) - $start, 1000));
    });
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->name = 'socket-io-load';
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-load.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-load.log';
Worker::runAll();
