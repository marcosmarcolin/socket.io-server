<?php

/**
 * The server of the soak test (tests/Soak/soak.mjs), on port 3000: one process, with connection state recovery,
 * a numbered broadcast every 200 ms, and a sample of what it holds every 30 seconds.
 *
 * Run by tests/Soak/run.sh: php tests/Soak/server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

// Short, so sessions kept for recovery expire many times during a run.
const RECOVERY_SECONDS = 30;

$io = new SocketIO(options: [
    'pingInterval' => 10_000,
    'pingTimeout' => 5_000,
    // RECOVERY=0 turns it off, to tell what it costs.
    'connectionStateRecovery' => getenv('RECOVERY') === '0'
        ? false
        : ['maxDisconnectionDuration' => RECOVERY_SECONDS * 1000],
]);

$io->on('connection', static function (Socket $socket): void {
    $socket->on('echo', static function (mixed $data, Closure $ack): void {
        $ack($data);
    });
    $socket->on('join', static function (string $room) use ($socket): void {
        $socket->join($room);
    });
    $socket->on('leave', static function (string $room) use ($socket): void {
        $socket->leave($room);
    });
    $socket->on('binary', static function (Binary $data, Closure $ack): void {
        $ack(new Binary(strrev($data->data)));
    });
});

// What one namespace's adapter holds that nothing else shows: its rooms and the sessions kept for recovery.
$held = static function (SocketIO $io): array {
    $adapter = $io->sockets->adapter();
    $rooms = (new ReflectionProperty(InMemoryAdapter::class, 'rooms'))->getValue($adapter);
    $sessions = (new ReflectionProperty(InMemoryAdapter::class, 'sessions'))->getValue($adapter);
    $packets = (new ReflectionProperty(InMemoryAdapter::class, 'packets'))->getValue($adapter);

    return [
        'rooms' => is_array($rooms) ? count($rooms) : -1,
        'recoverySessions' => is_array($sessions) ? count($sessions) : -1,
        'recoveryPackets' => is_array($packets) ? count($packets) : -1,
    ];
};

$sample = static function () use ($io, $held): void {
    gc_collect_cycles();
    $status = (string) @file_get_contents('/proc/self/status');
    $rss = preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $match) === 1 ? (int) $match[1] * 1024 : 0;
    Worker::safeEcho('SOAK ' . json_encode([
        'time' => time(),
        'heap' => memory_get_usage(),
        'rss' => $rss,
        'sessions' => $io->engine->clientsCount(),
        'sockets' => count($io->sockets->sockets()),
        'timers' => Worker::getEventLoop()->getTimerCount(),
    ] + $held($io)) . "\n");
};

$io->on('workerStart', static function () use ($io, $sample): void {
    $sequence = 0;
    // The numbered broadcast every client checks: none lost, none twice, in order.
    // TICKS=0 turns it off, to tell what it costs.
    Timer::add(getenv('TICKS') === '0' ? 3600 : 0.2, static function () use ($io, &$sequence): void {
        $io->emit('tick', ++$sequence);
    });
    Timer::add(30, $sample);
    $sample();
});

// Asked by run.sh once the clients are gone, to see what is left.
$io->on('connection', static function (Socket $socket) use ($sample): void {
    $socket->on('sample', static function (Closure $ack) use ($sample): void {
        $sample();
        $ack();
    });
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->name = 'socket-io-soak';
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-soak.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-soak.log';
Worker::runAll();
