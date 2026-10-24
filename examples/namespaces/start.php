<?php

/**
 * Namespaces and middlewares: an open /public namespace, and an /admin namespace that needs a token and can
 * make announcements to /public.
 *
 *   php examples/namespaces/start.php start
 *
 * Then open http://localhost:3001. Socket.IO listens on port 3000; the admin token is "secret".
 */

declare(strict_types=1);

use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(3000);
$public = $io->of('/public');
$admin = $io->of('/admin');

$public->on('connection', static function (Socket $socket) use ($public): void {
    $socket->on('message', static function (string $text) use ($public): void {
        $public->emit('message', $text);
    });
});

// Runs before each connection to /admin: a ConnectionError refuses it, and the client gets its data.
$admin->use(static function (Socket $socket, Closure $next): void {
    if (($socket->handshake['auth']['token'] ?? null) === 'secret') {
        $next();

        return;
    }
    $next(new ConnectionError('forbidden', ['hint' => 'the token is "secret"']));
});

$admin->on('connection', static function (Socket $socket) use ($public): void {
    $socket->on('announce', static function (string $text, Closure $ack) use ($public): void {
        $public->emit('announcement', $text);
        $ack(count($public->sockets()));
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
