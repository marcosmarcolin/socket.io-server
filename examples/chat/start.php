<?php

/**
 * A chat room: users join with a name, see who is typing, and get each other's messages. With connection state
 * recovery, a user whose connection drops for a moment comes back in the room, with the messages sent meanwhile.
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

$io = new SocketIO(3000, [
    // A user back within two minutes keeps their socket, name and room, and gets what they missed.
    'connectionStateRecovery' => ['maxDisconnectionDuration' => 120_000],
]);

// The users who joined are in this room, so its size is the number of users, recovered ones included.
const ROOM = 'chat';

$count = static fn (): int => count($io->in(ROOM)->fetchSockets());

$io->on('connection', static function (Socket $socket) use ($count): void {
    $username = static fn (): string => is_string($socket->data['username'] ?? null) ? $socket->data['username'] : '?';

    // Back after a short drop: the socket has its name and room again, and the others hear it.
    if ($socket->recovered && isset($socket->data['username'])) {
        $socket->broadcast->emit('user joined', ['username' => $username(), 'numUsers' => $count(), 'back' => true]);
    }

    $socket->on('add user', static function (string $name) use ($socket, $count): void {
        if (isset($socket->data['username'])) {
            return;
        }
        $socket->data['username'] = $name;
        $socket->join(ROOM);

        $socket->emit('login', ['numUsers' => $count()]);
        $socket->broadcast->emit('user joined', ['username' => $name, 'numUsers' => $count()]);
    });

    $socket->on('new message', static function (string $message) use ($socket, $username): void {
        $socket->broadcast->emit('new message', ['username' => $username(), 'message' => $message]);
    });

    $socket->on('typing', static function () use ($socket, $username): void {
        $socket->broadcast->emit('typing', ['username' => $username()]);
    });

    $socket->on('stop typing', static function () use ($socket, $username): void {
        $socket->broadcast->emit('stop typing', ['username' => $username()]);
    });

    // The socket has left the room by now, so the count no longer includes it.
    $socket->on('disconnect', static function () use ($socket, $count, $username): void {
        if (isset($socket->data['username'])) {
            $socket->broadcast->emit('user left', ['username' => $username(), 'numUsers' => $count()]);
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
