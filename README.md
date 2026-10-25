# socket.io-server

[![CI](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml)

**English** · [Português (Brasil)](README.pt-BR.md)

A [Socket.IO](https://socket.io) v4 server for PHP, built on [Workerman](https://github.com/walkor/workerman).

It speaks the current protocols, Engine.IO v4 and Socket.IO v5, so browsers and apps use the official `socket.io-client` v3 or v4. It passes the official Engine.IO and Socket.IO compliance suites, and CI runs it against the official client.

> **Work in progress.** It isn't published on Packagist yet, and the API may still change before the first release.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [The client side](#the-client-side)
- [Server options](#server-options)
  - [Cookies across origins](#cookies-across-origins)
- [API](#api)
- [Data: JSON and binary](#data-json-and-binary)
- [TLS](#tls)
- [Several processes](#several-processes)
- [Examples](#examples)
- [Migrating from phpsocket.io](#migrating-from-phpsocketio)
- [Status](#status)
- [Development](#development)
- [License](#license)

## Requirements

- PHP 8.1 or later, with the `json` extension, and `pcntl` and `posix` to run Workerman.
- [Workerman](https://github.com/walkor/workerman) 5.2.
- On the client: `socket.io-client` v3 or v4. For the 1.x and 2.x clients, use [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

Out of scope: WebTransport, JSONP polling, custom parsers (msgpack), dynamic namespaces, connection state recovery, and a Redis adapter.

## Installation

The package isn't on Packagist yet. Until the first release, install it from the Git repository:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/marcosmarcolin/socket.io-server"}],
    "require": {"marcosmarcolin/socket.io-server": "dev-main"}
}
```

## Quick start

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

Run it with `php server.php start` (`start -d` to run in the background, `stop`, `restart`, `status`: the usual Workerman commands).

## The client side

```js
import { io } from "socket.io-client";

const socket = io("http://localhost:3000", { auth: { token: "secret" } });

socket.on("hello", (word) => console.log(word));
socket.emit("chat", "hi", (status) => console.log(status)); // "delivered"
socket.on("question", (answer) => answer("blue")); // the server's timeout() emit
```

With several server processes on one port, add `transports: ["websocket"]` (see [Several processes](#several-processes)).

## Server options

`new SocketIO($port, $options)` takes these options. Times are in milliseconds.

| Option | Default | Meaning |
|---|---|---|
| `pingInterval` | `25000` | How often the server pings each client. |
| `pingTimeout` | `20000` | How long it waits for the pong before closing the connection. |
| `maxPayload` | `1000000` | Largest message, in bytes; longer HTTP bodies get 413. |
| `upgradeTimeout` | `10000` | How long an upgrade from polling to WebSocket may take. |
| `connectTimeout` | `45000` | How long a client may stay without joining a namespace. |
| `path` | `'/socket.io/'` | The URL path; the client's `path` option must match. |
| `cors` | `['origin' => '*']` | `origin` is `'*'`, a list of allowed origins, a `Closure` that decides per request, or `null` to send no CORS headers. `credentials` (`false` by default) lets browsers send cookies. |

Unknown options and wrong types throw `InvalidArgumentException`.

### Cookies across origins

When the page and the server differ in host or port, the browser sends cookies only if the client asks and the server allows it. A typical case is authenticating by a session cookie:

```js
const socket = io("https://example.com:3000", { withCredentials: true });
```

```php
use Marcolin\SocketIO\Engine\Request;

$io = new SocketIO(3000, [
    'cors' => [
        // The page's host, on any port; a list of origins works too.
        'origin' => fn (string $origin, Request $request): bool =>
            parse_url($origin, PHP_URL_HOST) === parse_url('//' . $request->header('Host'), PHP_URL_HOST),
        'credentials' => true,
    ],
]);

$io->on('connection', function (Socket $socket): void {
    $cookie = $socket->handshake['headers']['cookie'] ?? '';
});
```

With `credentials`, `origin` can't be `'*'`: browsers reject that pair, so it throws.

CORS only covers HTTP long-polling: browsers don't apply it to WebSocket, so a page on another site can open a WebSocket to your server, with your cookies if they are `SameSite=None`. To refuse other origins on every transport, check the `Origin` header in a middleware too:

```php
$io->use(function (Socket $socket, Closure $next): void {
    $origin = $socket->handshake['headers']['origin'] ?? '';
    $host = $socket->handshake['headers']['host'] ?? '';
    $sameHost = parse_url($origin, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST);
    $next($sameHost ? null : new ConnectionError('forbidden origin'));
});
```

## API

### `SocketIO`

| | |
|---|---|
| `new SocketIO(?int $port = null, array $options = [])` | With a port, it listens on `tcp://0.0.0.0:<port>`. Without one, call `attach()`. |
| `attach(Worker $worker)` | Serves the server on your own `tcp` or `ssl` worker, for another address, several processes or TLS. Call it before `Worker::runAll()`. |
| `$io->worker` | The attached worker, or `null`. |
| `of(string $name): Nsp` | The namespace with that name, created on first use. |
| `$io->sockets` | The main namespace, `/`. |
| `on('connection', fn (Socket $socket))` | The main namespace's `connection` event. |
| `on('workerStart' \| 'workerStop', fn (Worker $worker))` | Each process starting or stopping. |
| `use(fn (Socket $socket, Closure $next))` | A middleware on `/`. |
| `adapter(fn (Nsp $nsp): Adapter)` | How namespaces keep rooms and broadcast, such as `ChannelAdapter::factory()`. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()` | Broadcasting on `/`, as on a namespace. |

### Namespaces: `Nsp`

| | |
|---|---|
| `on('connection', fn (Socket $socket))` | A socket joined, after the middlewares let it in. `connect` is the same. |
| `use(fn (Socket $socket, Closure $next))` | Runs before each connection, in order. Call `$next()` to let the socket in, or `$next($error)` to refuse it; `$next` may be called later, from an asynchronous callback. A `ConnectionError` also sends its `data`. An exception thrown before `$next` refuses the socket. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()` | Broadcasting to the namespace. |
| `sockets(): array<string, Socket>` | The connected sockets, by id. |
| `allSockets(): list<string>` | Their ids. |
| `fetchSockets(): list<Socket>`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets(bool $close = false)` | Act on the selected sockets; use `to()` and `except()` first to select. |

### `Socket`

| | |
|---|---|
| `$socket->id` | 24 hexadecimal characters, different from the Engine.IO session id and per namespace. |
| `$socket->handshake` | `headers` (lower-case names), `time`, `address`, `xdomain`, `secure`, `issued` (ms), `url`, `query`, and `auth`, the client's `auth` option. |
| `$socket->data` | A free-form array for your own data. |
| `$socket->rooms`, `$socket->connected`, `$socket->disconnected` | Its rooms (its own id included) and its state. |
| `$socket->nsp`, `$socket->request`, `$socket->conn` | Its namespace, the HTTP request that opened the connection, and the Engine.IO session. |
| `on(string $event, Closure $listener)` | An event from the client. The listener gets the arguments, plus an acknowledgement `Closure` last when the client asked for one; it answers once. |
| `emit(string $event, ...$args)`, `send(...$args)` | Sends an event; a `Closure` as the last argument asks the client to acknowledge it. |
| `timeout(int $ms)`, `volatile`, `compress(bool)` | Emit modifiers, which return a new object. See [Acknowledgements](#acknowledgements). |
| `join(string\|array $rooms)`, `leave(string $room)` | Rooms. |
| `to()`, `in()`, `except()`, `broadcast` | Broadcasting to other sockets; the socket itself is left out. |
| `disconnect(bool $close = false)` | Leaves the namespace; with `true`, closes the whole connection. |

Events: `disconnecting` and `disconnect` get `(string $reason, ?Throwable $description)`; `disconnecting` still sees the rooms. The reasons are `transport close`, `transport error`, `ping timeout`, `parse error`, `forced close`, `server namespace disconnect`, `client namespace disconnect` and `forced server close`. `error` gets a `Throwable` when the client sent a packet that breaks the protocol, just before the connection closes.

The names `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` and `removeListener` are reserved: emitting them throws.

### Acknowledgements

```php
// The server asks; the callback gets the client's answer.
$socket->emit('question', 'colour?', function (mixed $answer): void {});

// With a timeout, the callback gets null and the answer, or a TimeoutException.
$socket->timeout(5000)->emit('question', 'colour?', function (?Throwable $error, mixed $answer): void {});

// The client asks; the last argument answers it.
$socket->on('sum', function (array $numbers, Closure $ack): void {
    $ack(array_sum($numbers));
});
```

Broadcasts can't take acknowledgements: passing a `Closure` throws.

### Broadcasting: `BroadcastOperator`

`to()`, `in()`, `except()` and `volatile` return an immutable operator:

```php
$io->emit('news', $data);                         // every socket of "/"
$io->to('room1')->to('room2')->emit('news');      // sockets in either room, once each
$io->except('muted')->emit('news');               // everyone but the sockets in "muted"
$io->of('/admin')->to($socketId)->emit('ping');   // one socket, by its id
$socket->broadcast->emit('joined', $socket->id);  // everyone but this socket
$socket->to('room1')->emit('typing');             // the room, but not this socket
$io->volatile->emit('position', $x, $y);          // skipped where the transport is busy
```

`compress()` exists for compatibility and has no effect: the server doesn't compress.

### Errors

- A middleware refuses a connection with `$next(new ConnectionError($message, $data))`: the client's `connect_error` gets `message` and `data`.
- A client packet that breaks the protocol closes its connection.
- An exception from your listeners closes the connection it came from and is logged with `Worker::log`; the worker keeps running.

## Data: JSON and binary

- JSON objects arrive as PHP associative arrays. PHP can't tell `{}` from `[]`, so send an empty object as `new stdClass()`.
- Binary data arrives as `Marcolin\SocketIO\Binary`, wherever it is in the data. Wrap bytes in `new Binary($bytes)` to send them; the client gets a `Buffer` or an `ArrayBuffer`.
- Strings must be valid UTF-8; for other bytes, use `Binary`.

## TLS

Create the worker with the certificate, mark it `ssl`, and attach it:

```php
$worker = new Worker('tcp://0.0.0.0:443', [
    'ssl' => ['local_cert' => '/path/to/cert.pem', 'local_pk' => '/path/to/key.pem', 'verify_peer' => false],
]);
$worker->transport = 'ssl';
$io->attach($worker);
```

## Several processes

Broadcasts reach the other processes through `ChannelAdapter` and a [workerman/channel](https://github.com/walkor/channel) server:

```sh
composer require workerman/channel
```

```php
<?php

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// The channel server, in a process of its own.
new ChannelServer('127.0.0.1', 2206);

$io = new SocketIO(options: ['cors' => ['origin' => ['https://example.com']]]);

// The adapter connects to the channel server, which needs each process's event loop.
$io->on('workerStart', function () use ($io): void {
    $io->adapter(ChannelAdapter::factory('127.0.0.1', 2206));
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->count = 4;
$worker->reusePort = true;
$io->attach($worker);

Worker::runAll();
```

- Clients must connect with `transports: ['websocket']`: polling needs every request of a session to reach the same process, which several processes on one port don't guarantee.
- Only broadcasts cross processes (`emit`, `to`, `except`, `broadcast`). `fetchSockets`, `allSockets`, `socketsJoin`, `socketsLeave` and `disconnectSockets` act on the current process.

## Examples

Each one runs with `php examples/<name>/start.php start`; then open http://localhost:3001.

| Example | Shows |
|---|---|
| [chat](examples/chat) | a chat room: names, typing indicator, join and leave notices |
| [rooms](examples/rooms) | `join`, `leave`, messages to a room, live member lists |
| [acks](examples/acks) | acknowledgements both ways, and the server's `timeout()` |
| [namespaces](examples/namespaces) | `/public` and `/admin`, a middleware with a token, `ConnectionError` |
| [binary](examples/binary) | file upload and download with `Binary` |
| [multi-process](examples/multi-process) | four processes and `ChannelAdapter` |

## Migrating from phpsocket.io

Moving from [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io): see [the migration guide](docs/migrating-from-phpsocket.io.md). Your clients move to `socket.io-client` v3 or v4.

## Status

Changes are listed in [CHANGELOG.md](CHANGELOG.md).

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
| Multi-process integration tests and a scale check (200 clients, 4,000 deliveries) | Done |
| Examples: chat, rooms, acknowledgements, namespaces, binary data, several processes | Done |
| Complete README with an API reference, and a migration guide from phpsocket.io | Done |
| First release | Next |

## Development

Everything runs in Docker; PHP and Composer are not needed on the host.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) and PHPStan (level max)
tests/Compliance/engine-io.sh                  # the official Engine.IO v4 test suite
tests/Compliance/socket-io.sh                  # the official Socket.IO v5 test suite
tests/Integration/run.sh                       # the official socket.io-client against this server
tests/Integration/run.sh multi-process         # the same over two processes and a channel server
tests/Integration/run.sh examples              # every example, each against its test
```

### Secret scanning

[gitleaks](https://github.com/gitleaks/gitleaks) runs in Docker and blocks commits that add secrets. Enable the hook once per clone:

```sh
git config core.hooksPath .githooks
```

Docker must be running to commit. CI also scans the whole history on every pull request.

## License

MIT
