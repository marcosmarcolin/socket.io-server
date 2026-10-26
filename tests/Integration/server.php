<?php

/**
 * The server the integration tests in tests/Integration/client talk to, on port 3000.
 *
 * Run by tests/Integration/run.sh: php tests/Integration/server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\ResponseHeaders;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(options: [
    'pingInterval' => 1000,
    'pingTimeout' => 1000,
    'connectTimeout' => 2000,
    // The session id in a cookie on the polling handshake, for sticky sessions.
    'cookie' => true,
]);

// A cookie on each session's first answer, and a header on every answer.
$io->engine->on('initial_headers', static function (ResponseHeaders $headers): void {
    $headers->append('Set-Cookie', 'io-test=1; Path=/');
});
$io->engine->on('headers', static function (ResponseHeaders $headers): void {
    $headers->set('X-Engine', 'php');
});
// The last refused request, for the client to read back.
$lastConnectionError = null;
$io->engine->on(
    'connection_error',
    static function (Request $request, int $code, string $message) use (&$lastConnectionError): void {
        $lastConnectionError = ['code' => $code, 'message' => $message, 'transport' => $request->query('transport')];
    },
);

// The adapter's room events: a room whose name starts with "watched:" is announced when it's created.
$io->sockets->adapter()->on('create-room', static function (string $room) use ($io): void {
    if (str_starts_with($room, 'watched:')) {
        $io->emit('room-created', $room);
    }
});

$io->use(static function (Socket $socket, Closure $next): void {
    $refused = ($socket->handshake['auth']['token'] ?? null) === 'refuse';
    $next($refused ? new ConnectionError('refused', ['reason' => 'bad token']) : null);
});

$io->on('connection', static function (Socket $socket) use ($io, &$lastConnectionError): void {
    // A socket middleware stops the events named "forbidden", and the error listener tells the client.
    $socket->use(static function (array $event, Closure $next): void {
        $next($event[0] === 'forbidden' ? new RuntimeException('forbidden event') : null);
    });
    $socket->on('error', static function (Throwable $error) use ($socket): void {
        $socket->emit('refused', $error->getMessage());
    });
    $socket->on('forbidden', static function () use ($socket): void {
        $socket->emit('let-through');
    });
    $socket->on('last-connection-error', static function (Closure $ack) use (&$lastConnectionError): void {
        $ack($lastConnectionError);
    });
    // The text messages written to this connection, seen through the session's packetCreate event.
    $created = [];
    $socket->conn->on('packetCreate', static function (Packet $packet) use (&$created): void {
        if ($packet->type === PacketType::Message && ! $packet->binary) {
            $created[] = $packet->data;
        }
    });
    // A big event, which a polling answer compresses unless told otherwise.
    $socket->on('big', static function (int $size, bool $compress) use ($socket): void {
        $socket->compress($compress)->emit('big', str_repeat('x', $size));
    });
    $socket->on('say', static function (string $word) use ($socket): void {
        $socket->emit('said', $word);
    });
    $socket->on('created', static function (Closure $ack) use (&$created): void {
        $ack($created);
    });

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

    // Records every event both ways, without acknowledgement callbacks, and reports them on request.
    $incoming = [];
    $outgoing = [];
    $socket->onAny(static function (string $event, mixed ...$args) use (&$incoming): void {
        $incoming[] = [$event, ...array_filter($args, static fn (mixed $arg): bool => ! $arg instanceof Closure)];
    });
    $socket->onAnyOutgoing(static function (string $event, mixed ...$args) use (&$outgoing): void {
        $outgoing[] = [$event, ...$args];
    });
    $socket->on('seen', static function (Closure $ack) use (&$incoming, &$outgoing): void {
        $ack(['incoming' => $incoming, 'outgoing' => $outgoing]);
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
