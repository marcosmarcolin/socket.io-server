<?php

/**
 * Live dashboards: a timer on the server pushes fresh numbers to every page watching a dashboard, every few seconds.
 *
 *   php examples/dashboard/start.php start
 *
 * Then open http://localhost:3001 in a few tabs and pick a dashboard. Socket.IO listens on port 3000.
 * DASHBOARD_INTERVAL sets the seconds between updates (2 by default).
 */

declare(strict_types=1);

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

const HISTORY = 30;

$interval = (float) (getenv('DASHBOARD_INTERVAL') ?: 2);
$startedAt = time();

$io = new SocketIO(3000);

/**
 * Each dashboard turns into a room: only its viewers get its updates.
 *
 * @var array<string, array{metrics: Closure(): array<string, int|float>, chart: string, history: list<float>}>
 */
$dashboards = [
    // Numbers from this process.
    'server' => [
        'metrics' => static fn (): array => [
            'connections' => count($io->sockets->sockets()),
            'memoryMb' => round(memory_get_usage() / 1_048_576, 2),
            'uptimeSeconds' => time() - $startedAt,
        ],
        'chart' => 'memoryMb',
        'history' => [],
    ],
    // Made up, as a database query would return them.
    'sales' => [
        'metrics' => static fn (): array => [
            'ordersPerMinute' => random_int(20, 60),
            'revenue' => random_int(1_000, 5_000),
            'activeCarts' => random_int(100, 300),
        ],
        'chart' => 'ordersPerMinute',
        'history' => [],
    ],
];

// Reads a dashboard's numbers now, and keeps its chart's history.
$refresh = static function (string $name) use (&$dashboards): array {
    $dashboard = &$dashboards[$name];
    $metrics = ($dashboard['metrics'])();
    $dashboard['history'] = array_slice([...$dashboard['history'], (float) $metrics[$dashboard['chart']]], -HISTORY);

    return [
        'dashboard' => $name,
        'time' => (int) (microtime(true) * 1000),
        'metrics' => $metrics,
        'chart' => ['metric' => $dashboard['chart'], 'points' => $dashboard['history']],
    ];
};

$io->on('connection', static function (Socket $socket) use (&$dashboards, $refresh): void {
    $socket->emit('dashboards', array_keys($dashboards));

    // Moves the page to another dashboard, and answers with its numbers at once instead of making it wait a tick.
    $socket->on('watch', static function (string $name, Closure $ack) use ($socket, &$dashboards, $refresh): void {
        if (! isset($dashboards[$name])) {
            $ack(['error' => 'unknown dashboard']);

            return;
        }
        foreach (array_keys($dashboards) as $other) {
            $socket->leave('dashboard:' . $other);
        }
        $socket->join('dashboard:' . $name);
        $ack($refresh($name));
    });
});

// The timer starts with each process, and only works for the dashboards someone is watching.
$io->on('workerStart', static function () use ($io, &$dashboards, $refresh, $interval): void {
    Timer::add($interval, static function () use ($io, &$dashboards, $refresh): void {
        foreach (array_keys($dashboards) as $name) {
            $room = 'dashboard:' . $name;
            if ($io->in($room)->allSockets() !== []) {
                // Volatile: a page that can't keep up skips an update instead of queueing old ones.
                $io->to($room)->volatile->emit('update', $refresh($name));
            }
        }
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
