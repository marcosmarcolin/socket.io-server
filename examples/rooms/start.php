<?php

/**
 * Rooms: join and leave rooms, message the others in a room, and watch its member list.
 *
 *   php examples/rooms/start.php start
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

// Tells everyone in the room who is there.
$sendMembers = static function (string $room) use ($io): void {
    $members = array_map(static fn (Socket $socket): string => $socket->id, $io->in($room)->fetchSockets());
    $io->to($room)->emit('members', ['room' => $room, 'members' => $members]);
};

$io->on('connection', static function (Socket $socket) use ($sendMembers): void {
    $socket->on('join room', static function (string $room, Closure $ack) use ($socket, $sendMembers): void {
        $socket->join($room);
        $ack($socket->rooms);
        $sendMembers($room);
    });

    $socket->on('leave room', static function (string $room, Closure $ack) use ($socket, $sendMembers): void {
        $socket->leave($room);
        $ack($socket->rooms);
        $sendMembers($room);
    });

    // Reaches the room's other members, not the sender.
    $socket->on('room message', static function (string $room, string $text) use ($socket): void {
        $socket->to($room)->emit('room message', ['room' => $room, 'from' => $socket->id, 'text' => $text]);
    });

    // The rooms are left before "disconnect"; "disconnecting" still sees them.
    $socket->on('disconnecting', static function () use ($socket, $sendMembers): void {
        foreach ($socket->rooms as $room) {
            if ($room !== $socket->id) {
                $socket->leave($room);
                $sendMembers($room);
            }
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
