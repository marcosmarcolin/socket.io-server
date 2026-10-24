<?php

/**
 * Acknowledgements both ways: the client asks the server, and the server asks the client, with a timeout.
 *
 *   php examples/acks/start.php start
 *
 * Then open http://localhost:3001. Socket.IO listens on port 3000.
 */

declare(strict_types=1);

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(3000);

$io->on('connection', static function (Socket $socket): void {
    // The client asks: a Closure as the last argument answers it.
    $socket->on('sum', static function (array $numbers, Closure $ack): void {
        $ack(array_sum(array_filter($numbers, 'is_numeric')));
    });

    // The server asks, and waits at most the given time for the answer.
    $socket->on('ask me', static function (int $timeout) use ($socket): void {
        $socket->timeout($timeout)->emit(
            'question',
            'What is your favourite colour?',
            static function (?Throwable $error, mixed $answer) use ($socket): void {
                $socket->emit('result', $error === null ? ['answer' => $answer] : ['error' => $error->getMessage()]);
            },
        );
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
