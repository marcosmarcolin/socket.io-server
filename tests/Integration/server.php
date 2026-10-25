<?php

/**
 * The server the integration tests in tests/Integration/client talk to, on port 3000.
 *
 * Run by tests/Integration/run.sh: php tests/Integration/server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(options: ['pingInterval' => 1000, 'pingTimeout' => 1000, 'connectTimeout' => 2000]);

$io->use(static function (Socket $socket, Closure $next): void {
    $refused = ($socket->handshake['auth']['token'] ?? null) === 'refuse';
    $next($refused ? new ConnectionError('refused', ['reason' => 'bad token']) : null);
});

$io->on('connection', static function (Socket $socket) use ($io): void {
    // Answers with the arguments it got, through the acknowledgement or as an event.
    $socket->on('echo', static function (mixed ...$args) use ($socket): void {
        $ack = array_pop($args);
        if ($ack instanceof Closure) {
            $ack(...$args);
        } else {
            $socket->emit('echo', ...[...$args, $ack]);
        }
    });

    // The server asks; the client's answer comes back as an event.
    $socket->on('ask-me', static function () use ($socket): void {
        $socket->emit('question', 'what is the answer?', static function (mixed $answer) use ($socket): void {
            $socket->emit('answer-received', $answer);
        });
    });
    $socket->on('ask-me-and-wait', static function () use ($socket): void {
        $socket->timeout(200)->emit('silent', static function (?Throwable $error) use ($socket): void {
            $socket->emit('timed-out', $error?->getMessage());
        });
    });
    $socket->on('never-answer', static function (): void {
    });

    $socket->on('get-binary', static function () use ($socket): void {
        $socket->emit('binary', ['file' => new Binary("\x00\x01\x02\xff"), 'name' => 'bytes.bin']);
    });

    $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
        $socket->join($room);
        $ack($socket->rooms);
    });
    $socket->on('to-room', static function (string $room, string $message) use ($socket): void {
        $socket->to($room)->emit('room-message', $message);
    });
    $socket->on('broadcast', static function (string $message) use ($socket): void {
        $socket->broadcast->emit('broadcast-message', $message);
    });
    $socket->on('everyone-except', static function (string $room, string $message) use ($io): void {
        $io->except($room)->emit('except-message', $message);
    });

    // Asks a room and sends back the answers, or the timeout and the answers so far.
    $socket->on('poll-room', static function (string $room, int $timeout) use ($io, $socket): void {
        $io->to($room)->timeout($timeout)->emit(
            'vote',
            $room,
            static function (?Throwable $error, array $votes) use ($socket): void {
                $socket->emit('votes', $error?->getMessage(), $votes);
            },
        );
    });

    $socket->on('disconnect-me', static function () use ($socket): void {
        $socket->disconnect();
    });
    $socket->on('disconnect', static function (string $reason) use ($io, $socket): void {
        $io->emit('left', $socket->id, $reason);
    });
});

$io->of('/admin')->on('connection', static function (Socket $socket): void {
    $socket->emit('admin-welcome', $socket->nsp->name);
});

// Dual-stack: the client connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-integration';
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-integration.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-integration.log';
Worker::runAll();
