<?php

/**
 * Several processes joined by Redis: four workers share port 3000, and RedisAdapter carries broadcasts between them
 * through a Redis server. The same code runs on several machines pointed at one Redis. Clients must use the
 * WebSocket transport.
 *
 *   docker run --rm -p 6379:6379 redis:7.4.2-alpine
 *   php examples/redis/start.php start
 *
 * Another Redis: REDIS_URL=redis://:password@host:6379 php examples/redis/start.php start
 *
 * Then open http://localhost:3001 in a few tabs: each shows the process it landed on.
 */

declare(strict_types=1);

use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$redisUrl = getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379';

$io = new SocketIO();

// The adapter connects to Redis, which needs each process's event loop. It reconnects by itself.
$io->on('workerStart', static function () use ($io, $redisUrl): void {
    $io->adapter(RedisAdapter::factory($redisUrl));
});

$io->on('connection', static function (Socket $socket) use ($io): void {
    $socket->emit('process', getmypid());

    $socket->on('chat message', static function (string $text) use ($io): void {
        // Reaches the clients of every process, on every machine.
        $io->emit('chat message', ['text' => $text, 'via' => getmypid()]);
    });
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->count = 4;
// Lets the kernel spread connections over the processes.
$worker->reusePort = true;
$io->attach($worker);

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
