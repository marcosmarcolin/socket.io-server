<?php

/**
 * A chat room: users join with a name, see who is typing, and get each other's messages.
 *
 *   php examples/chat/start.php start
 *
 * Then open http://localhost:3001 in two tabs. Socket.IO listens on port 3000.
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

// Names of the users in the room. One process, so a plain array is enough.
$users = [];

$io->on('connection', static function (Socket $socket) use (&$users): void {
    $socket->on('add user', static function (string $username) use ($socket, &$users): void {
        if (isset($socket->data['username'])) {
            return;
        }
        $socket->data['username'] = $username;
        $users[$socket->id] = $username;

        $socket->emit('login', ['numUsers' => count($users)]);
        $socket->broadcast->emit('user joined', ['username' => $username, 'numUsers' => count($users)]);
    });

    $socket->on('new message', static function (string $message) use ($socket): void {
        $socket->broadcast->emit('new message', [
            'username' => $socket->data['username'] ?? '?',
            'message' => $message,
        ]);
    });

    $socket->on('typing', static function () use ($socket): void {
        $socket->broadcast->emit('typing', ['username' => $socket->data['username'] ?? '?']);
    });

    $socket->on('stop typing', static function () use ($socket): void {
        $socket->broadcast->emit('stop typing', ['username' => $socket->data['username'] ?? '?']);
    });

    $socket->on('disconnect', static function () use ($socket, &$users): void {
        if (! isset($users[$socket->id])) {
            return;
        }
        unset($users[$socket->id]);
        $socket->broadcast->emit('user left', [
            'username' => $socket->data['username'] ?? '?',
            'numUsers' => count($users),
        ]);
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
