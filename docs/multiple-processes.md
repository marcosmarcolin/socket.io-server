# Several processes

Broadcasts and the socket helpers reach the other processes through an adapter, set in `workerStart` on every process:

- `ChannelAdapter`, on a [workerman/channel](https://github.com/walkor/channel) server: nothing else to run, good for one machine.
- `RedisAdapter`, on a Redis server: for several machines, or when you already run Redis. It needs no extra package.

Both work the same way: each broadcast is encoded once and published once, and every process delivers it to its own sockets.

## What crosses processes

- Broadcasts: `emit`, `to`, `except`, `broadcast`, `volatile`, and `timeout()->emit(..., $callback)`, whose callback gets the answers of every process.
- `socketsJoin()`, `socketsLeave()` and `disconnectSockets()`: `$io->in($userRoom)->disconnectSockets()` disconnects a user wherever their sockets are.
- `fetchSockets()` with a callback: the sockets of every server, as `RemoteSocket` when they are elsewhere (see the [API reference](api.md#remotesocket)).
- `serverSideEmit()`: events from one server to the others, which listen with `on()` on the same namespace. A `Closure` last gets one answer per other server:

```php
// On every server: answers the others.
$io->on('ping', function (string $from, Closure $ack): void {
    $ack(gethostname());
});

// On one server: asks the others, and gets one answer per server.
$io->serverSideEmit('ping', 'web-1', function (?Throwable $error, array $hosts): void {
    // $error is a TimeoutException when some server didn't answer within 5 seconds.
});
```

Each server knows how many others run the namespace from their heartbeats, sent after 5 seconds without publishing anything; a server silent for 10 seconds, or that closed, stops counting. The data of `serverSideEmit()` follows the same rules as `emit()`, `Binary` included.

## workerman/channel

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

## Redis

```php
<?php

use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(options: ['cors' => ['origin' => ['https://example.com']]]);

// The adapter connects to Redis, which needs each process's event loop. It reconnects by itself.
$io->on('workerStart', function () use ($io): void {
    $io->adapter(RedisAdapter::factory('redis://:password@10.0.0.5:6379'));
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->count = 4;
$worker->reusePort = true;
$io->attach($worker);

Worker::runAll();
```

Every argument is optional:

```php
RedisAdapter::factory(
    url: 'redis://127.0.0.1:6379',   // redis://[[user]:password@]host[:port], or rediss:// for TLS
    key: 'socket.io',                // the first part of every channel name
    onError: null,                   // fn (Throwable $error): connection failures and Redis errors
    tls: [],                         // PHP's ssl context options for rediss://
);
```

- The URL defaults to `redis://127.0.0.1:6379`. Reserved characters in the user or password are percent-encoded; a database number is accepted and ignored, as publish and subscribe channels are shared by every database.
- **TLS:** a `rediss://` URL connects over TLS and checks the server's certificate against the system's authorities, with the URL's host as its name, as managed Redis services (Amazon ElastiCache, Azure Cache, Upstash) need. `tls` takes [PHP's ssl context options](https://www.php.net/manual/en/context.ssl.php) for anything else: a private authority, a client certificate, or another name to check. On [Swoole's event loop](performance.md#on-swooles-event-loop), Workerman's TLS client connections hang in the handshake, so the adapter refuses `rediss://` there; the `Emitter`, which doesn't run in the loop, isn't affected.

```php
RedisAdapter::factory('rediss://:password@redis.internal:6380', tls: [
    'cafile' => '/etc/ssl/redis-ca.pem',     // a private authority
    'local_cert' => '/etc/ssl/app.pem',      // a client certificate, when Redis asks for one
]);
```

- Servers of different apps sharing one Redis need different `key`s, or they get each other's broadcasts.
- When Redis is unreachable, the adapter retries every second and reports the failure once to `onError` (`Worker::log` by default). Up to 1,000 publishes wait for the connection; messages published while a process was disconnected are lost, as always with Redis pub/sub.

## Sending from your PHP app: the Emitter

The `Emitter` sends to the clients from any PHP code that isn't a Socket.IO server, such as a PHP-FPM request in your app. It publishes to Redis what a server would, and the servers running `RedisAdapter` deliver it to their sockets:

```php
use Marcolin\SocketIO\Emitter;

$emitter = Emitter::redis('redis://127.0.0.1:6379');

$emitter->emit('announcement', 'Maintenance at 22:00');          // everyone on "/"
$emitter->to('user:42')->emit('order-approved', ['id' => 1001]); // one room, on every server
$emitter->of('/admin')->except('muted')->emit('alert', $alert);
$emitter->in('user:42')->disconnectSockets();                     // log a user out everywhere
$emitter->serverSideEmit('cache-clear', 'users');                 // the servers' on('cache-clear')
```

- It uses blocking I/O, so it needs no event loop. It connects on the first send and keeps the connection; `Emitter::redis($url, $key, $timeout, $tls)` takes the servers' URL and `key`, how long to wait for Redis, 5 seconds by default, and the same `tls` options as the adapter. Failures throw a `RuntimeException`.
- `of()`, `to()`, `in()`, `except()` and `volatile` return a new emitter, so one can be kept and reused.
- It only sends: no acknowledgements and no `fetchSockets()`, as in the reference's emitter packages. Data follows the rules of `emit()`, `Binary` included.
- Servers don't count an emitter as a server, so it never makes them wait for answers.
- The [emitter example](../examples/emitter) has a plain PHP endpoint that sends notifications to the open tabs.

## Limits

- With several processes on one port, clients must connect with `transports: ['websocket']`: polling needs every request of a session to reach the same process, which several processes on one port don't guarantee. Setting `'transports' => ['websocket']` on the server too refuses polling outright. Behind a proxy with sticky sessions and one port per process, polling works; see [Behind a reverse proxy](reverse-proxy.md).
- `fetchSockets()` without a callback and `allSockets()` read the current process.
- `local` keeps any of the above in the current process: `$io->local->emit('reload')`, `$io->local->disconnectSockets()`.
