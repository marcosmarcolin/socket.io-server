<?php

/**
 * Two worker processes on port 3000, joined by a workerman/channel server, or by Redis with ADAPTER=redis and
 * REDIS_URL, for tests/Integration/client/multi-process.test.js.
 *
 * Run by tests/Integration/run.sh multi-process and multi-process-redis:
 * php tests/Integration/multi-process-server.php start
 */

declare(strict_types=1);

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

const PROCESSES = 2;

$redisUrl = getenv('ADAPTER') === 'redis' ? (string) getenv('REDIS_URL') : null;
// The authority of the TLS-only Redis, for rediss:// URLs.
$redisTls = (string) getenv('REDIS_TLS_CA') === '' ? [] : ['cafile' => (string) getenv('REDIS_TLS_CA')];
if ($redisUrl === null) {
    new ChannelServer('127.0.0.1', 2206);
}

$io = new SocketIO(options: ['pingInterval' => 5000, 'pingTimeout' => 5000]);

// The broker connection needs each process's event loop, so the adapter is set when the process starts.
$io->on('workerStart', static function () use ($io, $redisUrl, $redisTls): void {
    $io->adapter(
        $redisUrl === null
            ? ChannelAdapter::factory('127.0.0.1', 2206)
            : RedisAdapter::factory($redisUrl, tls: $redisTls),
    );
});

$io->on('connection', static function (Socket $socket) use ($io, $redisUrl): void {
    $socket->on('whoami', static function (Closure $ack): void {
        $ack(getmypid());
    });
    $socket->on('join', static function (string $room, Closure $ack) use ($socket): void {
        $socket->join($room);
        $ack(getmypid());
    });
    $socket->on('everyone', static function (string $message) use ($io): void {
        $io->emit('everyone', $message);
    });
    $socket->on('everyone-here', static function (string $message) use ($io): void {
        $io->local->emit('local-message', $message);
    });
    $socket->on('to-rooms', static function (array $rooms, string $message) use ($io): void {
        $io->to(array_values(array_filter($rooms, 'is_string')))->emit('room-message', $message);
    });
    $socket->on('everyone-except', static function (string $room, string $message) use ($io): void {
        $io->except($room)->emit('except-message', $message);
    });
    $socket->on('broadcast', static function (string $message) use ($socket): void {
        $socket->broadcast->emit('broadcast-message', $message);
    });
    $socket->on('join-all', static function (string $room, Closure $ack) use ($io): void {
        $io->socketsJoin($room);
        $ack(true);
    });
    $socket->on('leave-all', static function (string $room, Closure $ack) use ($io): void {
        $io->socketsLeave($room);
        $ack(true);
    });
    $socket->on('kick', static function (string $socketId) use ($io): void {
        $io->in($socketId)->disconnectSockets();
    });
    $socket->on('set-name', static function (string $name, Closure $ack) use ($socket): void {
        $socket->data['name'] = $name;
        $ack(true);
    });
    // The sockets in a room, on every process.
    $socket->on('fetch', static function (string $room, Closure $ack) use ($io): void {
        $io->in($room)->fetchSockets(static function (?Throwable $error, array $sockets) use ($ack): void {
            $ack([
                'error' => $error?->getMessage(),
                'sockets' => array_map(static fn (Socket|RemoteSocket $socket): array => [
                    'id' => $socket->id,
                    'name' => is_array($socket->data) ? ($socket->data['name'] ?? null) : null,
                    'remote' => $socket instanceof RemoteSocket,
                ], $sockets),
            ]);
        });
    });
    // Emits to each socket of a room, found with fetchSockets().
    $socket->on('poke', static function (string $room) use ($io): void {
        $io->in($room)->fetchSockets(static function (?Throwable $error, array $sockets): void {
            foreach ($sockets as $socket) {
                $socket->emit('poked', getmypid());
            }
        });
    });
    // Asks every client, on every process, for its pid.
    $socket->on('ask-all', static function (Closure $ack) use ($io): void {
        $io->timeout(3000)->emit('question', static function (?Throwable $error, array $answers) use ($ack): void {
            $ack(['error' => $error?->getMessage(), 'answers' => $answers]);
        });
    });
    // Asks one socket, found with fetchSockets() wherever it is.
    $socket->on('ask-one', static function (string $socketId, Closure $ack) use ($io): void {
        $io->in($socketId)->fetchSockets(static function (?Throwable $error, array $sockets) use ($ack): void {
            $reply = static function (?Throwable $error, mixed $answer) use ($ack): void {
                $ack(['error' => $error?->getMessage(), 'answer' => $answer]);
            };
            $sockets[0]->timeout(3000)->emit('question', $reply);
        });
    });
    // Runs another PHP process that emits through Redis with the Emitter, as a PHP-FPM request would.
    $emitFromAnotherProcess = static function (string $room, string $message, Closure $ack) use ($redisUrl): void {
        if ($redisUrl === null) {
            $ack(['skipped' => true]);

            return;
        }
        $command = [PHP_BINARY, __DIR__ . '/emitter.php', $room, $message];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1', $output, $status);
        $ack(['skipped' => false, 'status' => $status, 'output' => implode("\n", $output)]);
    };
    $socket->on('emit-from-another-process', $emitFromAnotherProcess);
    // Asks the other processes for their pid.
    $socket->on('servers', static function (Closure $ack) use ($io): void {
        $io->serverSideEmit('pid', static function (?Throwable $error, array $pids) use ($ack): void {
            $ack(['error' => $error?->getMessage(), 'pids' => $pids]);
        });
    });
    // Sends numbered events to every client, for the scale check.
    $socket->on('flood', static function (int $count) use ($io): void {
        for ($i = 0; $i < $count; $i++) {
            $io->emit('flood', $i);
        }
    });
});

$io->on('pid', static function (Closure $ack): void {
    $ack(getmypid());
});

// Dual-stack: the client connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'socket-io-multi-process';
$worker->count = PROCESSES;
// Lets the kernel spread connections over the processes.
$worker->reusePort = true;
$io->attach($worker);

Worker::$pidFile = sys_get_temp_dir() . '/socket-io-multi-process.pid';
Worker::$logFile = sys_get_temp_dir() . '/socket-io-multi-process.log';
Worker::runAll();
