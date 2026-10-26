<?php

/**
 * Private messages: each user has a room of their own, so a message reaches every tab of the recipient and of the
 * sender. A session id in the auth payload keeps the user across reconnections, and "user connected" and
 * "user disconnected" follow the first and the last tab of each user.
 *
 *   php examples/private-messaging/start.php start
 *
 * Then open http://localhost:3001 in a few tabs and private windows, with different names.
 *
 * The users live in this process's memory. With several processes, keep them in Redis or a database, and use
 * fetchSockets() with a callback to count the tabs on every process.
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

/** @var array<string, array{userId: string, username: string}> sessions by session id */
$sessions = [];

// A known session id restores its user; otherwise a username starts a new session.
$io->use(static function (Socket $socket, Closure $next) use (&$sessions): void {
    $auth = $socket->handshake['auth'];
    $sessionId = $auth['sessionId'] ?? null;
    if (is_string($sessionId) && isset($sessions[$sessionId])) {
        $socket->data = ['sessionId' => $sessionId] + $sessions[$sessionId];
        $next();

        return;
    }
    $username = $auth['username'] ?? null;
    if (! is_string($username) || trim($username) === '') {
        $next(new ConnectionError('a username is required'));

        return;
    }
    $sessionId = bin2hex(random_bytes(16));
    $sessions[$sessionId] = ['userId' => bin2hex(random_bytes(8)), 'username' => trim($username)];
    $socket->data = ['sessionId' => $sessionId] + $sessions[$sessionId];
    $next();
});

$io->on('connection', static function (Socket $socket) use ($io, &$sessions): void {
    // Set by the middleware above.
    $userId = is_string($socket->data['userId'] ?? null) ? $socket->data['userId'] : '';
    $username = is_string($socket->data['username'] ?? null) ? $socket->data['username'] : '';
    $room = 'user:' . $userId;
    $socket->join($room);

    // The client keeps these, to come back as the same user.
    $socket->emit('session', ['sessionId' => $socket->data['sessionId'], 'userId' => $userId]);

    // Every user, and whether one of their tabs is open.
    $users = [];
    foreach ($sessions as $session) {
        $users[$session['userId']] = [
            'userId' => $session['userId'],
            'username' => $session['username'],
            'connected' => $io->in('user:' . $session['userId'])->fetchSockets() !== [],
        ];
    }
    $socket->emit('users', array_values($users));

    // Only the first tab of a user announces them.
    if (count($io->in($room)->fetchSockets()) === 1) {
        $socket->broadcast->emit('user connected', ['userId' => $userId, 'username' => $username]);
    }

    // To every tab of the recipient, and to the sender's other tabs too.
    $socket->on('private message', static function (string $to, string $content) use ($socket, $userId): void {
        $socket->to('user:' . $to)->to('user:' . $userId)->emit('private message', [
            'content' => $content,
            'from' => $userId,
            'to' => $to,
        ]);
    });

    // The socket left its rooms by now: if the room is empty, that was the user's last tab.
    $socket->on('disconnect', static function () use ($io, $room, $userId): void {
        if ($io->in($room)->fetchSockets() === []) {
            $io->emit('user disconnected', $userId);
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
