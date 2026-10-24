<?php

/**
 * Several processes: four workers share port 3000, and ChannelAdapter carries broadcasts between them through a
 * workerman/channel server. Clients must use the WebSocket transport.
 *
 *   php examples/multi-process/start.php start
 *
 * Then open http://localhost:3001 in a few tabs: each shows the process it landed on.
 */

declare(strict_types=1);

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

// The channel server, in a process of its own.
new ChannelServer('127.0.0.1', 2206);

$io = new SocketIO();

// The adapter connects to the channel server, which needs each process's event loop.
$io->on('workerStart', static function () use ($io): void {
    $io->adapter(ChannelAdapter::factory('127.0.0.1', 2206));
});

$io->on('connection', static function (Socket $socket) use ($io): void {
    $socket->emit('process', getmypid());

    $socket->on('chat message', static function (string $text) use ($io): void {
        // Reaches the clients of every process.
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
