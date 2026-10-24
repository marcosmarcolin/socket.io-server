# socket.io-server

**English** · [Português (Brasil)](README.pt-BR.md)

A [Socket.IO](https://socket.io) v4 server for PHP, built on [Workerman](https://github.com/walkor/workerman).

> **Work in progress.** The server works and passes the official Engine.IO and Socket.IO compliance suites, but it isn't published on Packagist yet and its API may still change.

## Scope

- Speaks the Engine.IO v4 and Socket.IO v5 protocols, for `socket.io-client` v3 and v4.
- Transports: HTTP long-polling and WebSocket, with the upgrade from polling to WebSocket.
- PHP >= 8.1 and Workerman 5. No framework required.

For `socket.io-client` v1 and v2, use [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Status

| Step | State |
|---|---|
| Engine.IO packet parser | Done |
| Engine.IO session: heartbeat, write buffer, polling and WebSocket transports, upgrade | Done |
| Engine.IO server: request validation, sessions, CORS | Done |
| Workerman integration: one port for HTTP and WebSocket, timers, connection adapters | Done |
| The official Engine.IO v4 test suite running in CI (25 of 25 passing) | Done |
| Socket.IO v5 parser: encoder, decoder, binary attachments | Done |
| Socket.IO server: connection, namespaces, middlewares, events, acks, rooms | Done |
| Broadcast to rooms and namespaces | Done |
| The official Socket.IO v5 test suite running in CI (32 of 32 passing) | Done |
| Integration tests with the official `socket.io-client` 4.8.4: polling, WebSocket, upgrade, namespaces, rooms, acks, binary | Done |
| Cross-process broadcast: `ChannelAdapter` on `workerman/channel` | Done |
| Multi-process integration tests and a scale check | Next |

## Usage

```php
<?php

use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(3000, [
    'pingInterval' => 25000,
    'pingTimeout' => 20000,
    'cors' => ['origin' => ['https://example.com']],
]);

// Runs before each connection to "/".
$io->use(function (Socket $socket, Closure $next): void {
    $token = $socket->handshake['auth']['token'] ?? null;
    $next($token === 'secret' ? null : new ConnectionError('unauthorized'));
});

$io->on('connection', function (Socket $socket) use ($io): void {
    $socket->data['joinedAt'] = time();
    $socket->join('lobby');
    $socket->emit('hello', 'world');
    $socket->broadcast->emit('joined', $socket->id);

    // A Closure as the last argument is the client's acknowledgement callback.
    $socket->on('chat', function (string $message, ?Closure $ack = null) use ($socket): void {
        $socket->to('lobby')->emit('chat', $message);
        if ($ack !== null) {
            $ack('delivered');
        }
    });

    $socket->timeout(5000)->emit('question', function (?Throwable $error, mixed $answer): void {
        // $error is a TimeoutException when the client didn't answer in time.
    });

    $socket->on('disconnect', function (string $reason) use ($io, $socket): void {
        $io->to('lobby')->emit('left', $socket->id, $reason);
    });
});

$io->of('/admin')->on('connection', function (Socket $socket): void {
    $socket->emit('welcome', ['role' => 'admin']);
});

Worker::runAll();
```

Run it with `php server.php start`. To pick the address, the number of processes or TLS, create the `Worker` yourself and call `$io->attach($worker)` before `Worker::runAll()`.

JSON objects arrive as PHP arrays. To send an empty object, use `new stdClass()`. To send binary data, wrap it in `Marcolin\SocketIO\Binary`.

For now, run a single worker process: broadcasting across processes comes with `ChannelAdapter`. With several processes on one port, clients will also need `transports: ['websocket']`, since polling needs sticky sessions.

## Development

Everything runs in Docker; PHP and Composer are not needed on the host.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) and PHPStan (level max)
tests/Compliance/engine-io.sh                  # the official Engine.IO v4 test suite
tests/Compliance/socket-io.sh                  # the official Socket.IO v5 test suite
tests/Integration/run.sh                       # the official socket.io-client against this server
```

### Secret scanning

[gitleaks](https://github.com/gitleaks/gitleaks) runs in Docker and blocks commits that add secrets. Enable the hook once per clone:

```sh
git config core.hooksPath .githooks
```

Docker must be running to commit. CI also scans the whole history on every pull request.

## License

MIT
