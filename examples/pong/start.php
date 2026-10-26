<?php

/**
 * Multiplayer Pong: players wait in a queue, play in pairs, one room per match, and the server runs the game.
 *
 *   php examples/pong/start.php start
 *
 * Open http://localhost:3001 in two tabs (or two machines on the network) and press Play in both.
 * Socket.IO listens on port 3000.
 *
 * The server is the authority: 30 times a second it moves the paddles and the ball and sends the state, volatile,
 * so a slow player skips frames instead of lagging behind. The score and the end of the match aren't volatile.
 */

declare(strict_types=1);

use Examples\Pong\Lobby;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Game.php';
require __DIR__ . '/Lobby.php';

const FRAMES_PER_SECOND = 30;

$io = new SocketIO(3000);

// The queue and the matches; one process, so they are plain objects in its memory.
$lobby = new Lobby($io);

$io->on('connection', static function (Socket $socket) use ($lobby): void {
    $socket->on('play', static fn (string $name) => $lobby->play($socket, $name));
    $socket->on('move', static fn (int $direction) => $lobby->move($socket, $direction));
    $socket->on('disconnect', static fn () => $lobby->leave($socket));
});

// The game loop, in the worker once it starts: Workerman's timer is the user-facing way to run periodic work.
$io->on('workerStart', static function () use ($lobby): void {
    Timer::add(1 / FRAMES_PER_SECOND, static fn () => $lobby->tick(1 / FRAMES_PER_SECOND));
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
