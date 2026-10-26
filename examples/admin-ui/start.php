<?php

/**
 * The chat example, instrumented for the official Admin UI: watch its sockets, rooms and events live, and act on
 * them, from https://admin.socket.io or a self-hosted copy of the UI.
 *
 *   php examples/admin-ui/start.php start
 *
 * Open http://localhost:3001 in two tabs to chat. With DEBUG=1, the console logs each connection's life:
 *
 *   DEBUG=1 php examples/admin-ui/start.php start
 *
 * Then open https://admin.socket.io, with the server URL http://localhost:3000, the username admin and the
 * password admin (or ADMIN_PASSWORD).
 */

declare(strict_types=1);

use Marcolin\SocketIO\Admin\AdminUi;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(3000, [
    // The chat page, and the Admin UI from its own origin: the hosted one, or a copy served on port 3002.
    'cors' => [
        'origin' => [
            'http://localhost:3001',
            'http://127.0.0.1:3001',
            'https://admin.socket.io',
            'http://localhost:3002',
            'http://127.0.0.1:3002',
        ],
        'credentials' => true,
    ],
    // DEBUG=1 logs each connection's life (opened, upgraded, closed with its reason) to the console.
    'logger' => getenv('DEBUG') ? static function (string $scope, string $message): void {
        Worker::log("$scope: $message");
    } : null,
]);

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

// The Admin UI's data: everything it shows comes from the /admin namespace. The password is stored hashed only.
AdminUi::instrument($io, [
    'auth' => [
        'type' => 'basic',
        'username' => 'admin',
        'password' => password_hash(getenv('ADMIN_PASSWORD') ?: 'admin', PASSWORD_BCRYPT),
    ],
]);

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
