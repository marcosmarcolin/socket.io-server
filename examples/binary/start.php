<?php

/**
 * Binary data: the client uploads a file and gets its size and hash back, and downloads bytes the server makes.
 *
 *   php examples/binary/start.php start
 *
 * Then open http://localhost:3001. Socket.IO listens on port 3000.
 */

declare(strict_types=1);

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(3000, ['maxPayload' => 5_000_000]);

$io->on('connection', static function (Socket $socket): void {
    // Binary values arrive as Binary, wherever they are in the data.
    $socket->on('upload', static function (array $file, Closure $ack): void {
        $data = $file['data'] ?? null;
        if (! $data instanceof Binary) {
            $ack(['error' => 'no file']);

            return;
        }
        $ack(['name' => $file['name'] ?? '', 'size' => strlen($data->data), 'sha256' => hash('sha256', $data->data)]);
    });

    // A Binary anywhere in what you send goes as a binary attachment.
    $socket->on('download', static function (int $size, Closure $ack): void {
        $size = max(1, min($size, 1_000_000));
        $ack(['name' => 'random.bin', 'data' => new Binary(random_bytes($size))]);
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
