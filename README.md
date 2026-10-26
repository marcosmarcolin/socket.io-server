# socket.io-server

[![CI](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/marcosmarcolin/socket.io-server)](https://packagist.org/packages/marcosmarcolin/socket.io-server)
[![Downloads](https://img.shields.io/packagist/dt/marcosmarcolin/socket.io-server)](https://packagist.org/packages/marcosmarcolin/socket.io-server)
[![PHP version](https://img.shields.io/packagist/dependency-v/marcosmarcolin/socket.io-server/php)](https://packagist.org/packages/marcosmarcolin/socket.io-server)

**English** · [Português (Brasil)](README.pt-BR.md)

A [Socket.IO](https://socket.io) v4 server for PHP, built on [Workerman](https://github.com/walkor/workerman).

It speaks the current protocols, Engine.IO v4 and Socket.IO v5, so browsers and apps use the official `socket.io-client` v3 or v4. It passes the official Engine.IO and Socket.IO compliance suites, and CI runs it against the official client.

> **Version 0.x.** The API may still change between minor versions until 1.0; each change is listed in the [changelog](CHANGELOG.md).

## Features

- HTTP long-polling and WebSocket on one port, with the upgrade from polling to WebSocket.
- Namespaces, middlewares, rooms, acknowledgements (broadcasts with a timeout too), binary data and catch-all listeners.
- Several processes and machines, through [workerman/channel](https://github.com/walkor/channel) or Redis.
- CORS with cookies, a hook to refuse connections before the handshake, and TLS.
- An API shaped like [phpsocket.io](https://github.com/walkor/phpsocket.io)'s, to ease migration.

## Requirements

- PHP 8.1 or later, with the `json` extension, and `pcntl` and `posix` to run Workerman.
- [Workerman](https://github.com/walkor/workerman) 5.2.
- On the client: `socket.io-client` v3 or v4. For the 1.x and 2.x clients, use [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Installation

```sh
composer require marcosmarcolin/socket.io-server
```

## Quick start

`server.php`:

```php
<?php

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(3000, ['cors' => ['origin' => ['https://example.com']]]);

$io->on('connection', function (Socket $socket) use ($io): void {
    $socket->join('lobby');
    $socket->emit('hello', 'world');

    // A Closure as the last argument is the client's acknowledgement callback.
    $socket->on('chat', function (string $message, ?Closure $ack = null) use ($io): void {
        $io->to('lobby')->emit('chat', $message);
        if ($ack !== null) {
            $ack('delivered');
        }
    });
});

Worker::runAll();
```

Run it with `php server.php start` (`start -d` to run in the background, `stop`, `restart`, `status`: the usual Workerman commands). Then, on the client:

```js
import { io } from "socket.io-client";

const socket = io("http://localhost:3000");

socket.on("hello", (word) => console.log(word));
socket.on("chat", (message) => console.log(message));
socket.emit("chat", "hi", (status) => console.log(status)); // "delivered"
```

## Examples

Each one runs with `php examples/<name>/start.php start`; then open http://localhost:3001.

| Example | Shows |
|---|---|
| [chat](examples/chat) | a chat room: names, typing indicator, join and leave notices |
| [session-auth](examples/session-auth) | login with a PHP session, and a middleware that lets in only logged-in sessions' sockets |
| [rooms](examples/rooms) | `join`, `leave`, messages to a room, live member lists |
| [acks](examples/acks) | acknowledgements both ways, and the server's `timeout()` |
| [namespaces](examples/namespaces) | `/public` and `/admin`, a middleware with a token, `ConnectionError` |
| [binary](examples/binary) | file upload and download with `Binary` |
| [dashboard](examples/dashboard) | live dashboards: a server timer pushes new numbers every few seconds to the viewers of each one |
| [multi-process](examples/multi-process) | four processes and `ChannelAdapter` |
| [redis](examples/redis) | four processes and `RedisAdapter`; it needs a Redis server |

## Documentation

- [Configuration](docs/configuration.md): server options, CORS and cookies, refusing connections, TLS.
- [API reference](docs/api.md): `SocketIO`, namespaces, sockets, acknowledgements, broadcasting, JSON and binary data.
- [Several processes](docs/multiple-processes.md): `ChannelAdapter` and `RedisAdapter`.
- [Migrating from phpsocket.io](docs/migrating-from-phpsocket.io.md): moving an app and its clients.
- [Development](docs/development.md): tests, linters and the compliance suites, all in Docker.
- [Changelog](CHANGELOG.md).

## License

MIT
