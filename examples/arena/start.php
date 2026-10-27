<?php

/**
 * A multiplayer space arena: everyone flies in one arena, shoots the others, and climbs the scoreboard, with bots
 * while few people play. The server runs the game; players only send how they steer.
 *
 *   php examples/arena/start.php start
 *
 * Open http://localhost:3001 in a few tabs, or on phones on the same network. Socket.IO listens on port 3000.
 *
 * 30 times a second the server moves everything and sends the arena's state, volatile, so a slow player skips frames
 * instead of lagging behind; kills and the scoreboard aren't volatile. With connection state recovery, a player whose
 * connection drops for a moment gets their ship back, where it drifted.
 */

declare(strict_types=1);

use Examples\Arena\Arena;
use Marcolin\SocketIO\DisconnectReason;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Ship.php';
require __DIR__ . '/Arena.php';

const FRAMES_PER_SECOND = 30;
const ROOM = 'arena';

$io = new SocketIO(3000, [
    // A player back within ten seconds keeps their ship: the arena waits that long for them.
    'connectionStateRecovery' => ['maxDisconnectionDuration' => 10_000],
]);

// One process, so the arena is a plain object in its memory.
$arena = new Arena();

$io->on('connection', static function (Socket $socket) use ($io, $arena): void {
    // Back after a short drop: the ship and the room are theirs again.
    if ($socket->recovered && $arena->back($socket->id)) {
        $socket->emit('scores', $arena->scores());
    }

    $socket->on('join', static function (string $name, Closure $ack) use ($socket, $io, $arena): void {
        if ($arena->has($socket->id)) {
            return;
        }
        $name = mb_substr(trim($name), 0, 16) ?: 'pilot';
        $socket->join(ROOM);
        $ack($arena->join($socket->id, $name));
        $io->to(ROOM)->emit('scores', $arena->scores());
    });

    // How the player steers: held keys, sent only when they change.
    $socket->on('steer', static function (int $turn, bool $thrust, bool $fire) use ($socket, $arena): void {
        $arena->steer($socket->id, $turn, $thrust, $fire);
    });

    // A drop the client didn't ask for leaves the ship waiting for recovery; leaving on purpose takes it out.
    $socket->on('disconnect', static function (string $reason) use ($socket, $io, $arena): void {
        if (DisconnectReason::from($reason)->isRecoverable()) {
            $arena->drop($socket->id);

            return;
        }
        $arena->remove($socket->id);
        $io->to(ROOM)->emit('scores', $arena->scores());
    });
});

// The game loop, in the worker once it starts: Workerman's timer is the user-facing way to run periodic work.
$io->on('workerStart', static function () use ($io, $arena): void {
    $scores = [];
    Timer::add(1 / FRAMES_PER_SECOND, static function () use ($io, $arena, &$scores): void {
        foreach ($arena->step(1 / FRAMES_PER_SECOND) as $kill) {
            $io->to(ROOM)->emit('kill', $kill);
        }
        // The scoreboard goes out when it changes: a kill, a bot in or out, a ship gone for good.
        $now = $arena->scores();
        if ($now !== $scores) {
            $scores = $now;
            $io->to(ROOM)->emit('scores', $now);
        }
        $io->to(ROOM)->volatile->emit('state', $arena->state());
    });
});

// Serves the page.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
