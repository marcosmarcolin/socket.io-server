# Several processes

Broadcasts and the socket helpers reach the other processes through an adapter, set in `workerStart` on every process:

- `ChannelAdapter`, on a [workerman/channel](https://github.com/walkor/channel) server: nothing else to run, good for one machine.
- `RedisAdapter`, on Redis publish and subscribe: for several machines, or when you already run Redis. It needs no extra package.
- `RedisStreamsAdapter`, on a Redis stream: a process that loses Redis for a while catches up on what it missed, and [connection state recovery](configuration.md#connection-state-recovery) works on any process, without sticky sessions. See [which Redis adapter](#which-redis-adapter).

All three work the same way: each broadcast is encoded once and sent once, and every process delivers it to its own sockets.

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

## Redis Streams

`RedisStreamsAdapter`, as the reference's [Redis Streams adapter](https://socket.io/docs/v4/redis-streams-adapter/), appends broadcasts, `socketsJoin()`, `socketsLeave()` and `disconnectSockets()` to a Redis stream that every process reads. The rest of the cluster protocol (`serverSideEmit()`, `fetchSockets()`, broadcasts with acknowledgements, heartbeats) only matters while someone waits for it, so it stays on publish and subscribe, as in the reference. It needs Redis 6.2 or later, or Valkey.

```php
use Marcolin\SocketIO\Adapter\RedisStreamsAdapter;

$io = new SocketIO(options: ['connectionStateRecovery' => true]);

$io->on('workerStart', function () use ($io): void {
    $io->adapter(RedisStreamsAdapter::factory('redis://:password@10.0.0.5:6379'));
});
```

It takes `RedisAdapter`'s arguments, `url`, `key`, `onError` and `tls`, which work the same, plus `options`, with the reference's names and defaults:

```php
RedisStreamsAdapter::factory('redis://127.0.0.1:6379', options: [
    'streamName' => 'socket.io',          // the stream every process appends to and reads
    'maxLen' => 10_000,                   // about how many entries the stream keeps (XADD MAXLEN ~)
    'readCount' => 100,                   // entries per XREAD or XRANGE call
    'blockTimeInMs' => 5_000,             // how long one XREAD waits before it is sent again
    'sessionKeyPrefix' => 'sio:session:', // the keys of the sessions kept for connection state recovery
]);
```

- **An outage loses nothing that reached Redis.** Each process reads the stream from the last entry it got, so after a reconnection it reads what was appended meanwhile, in order. While a process is disconnected, up to 1,000 commands wait for the connection, as with `RedisAdapter`. What goes past `maxLen` is trimmed, so a process away for long enough misses the oldest entries.
- **Connection state recovery works on any process.** With the `connectionStateRecovery` option, an event's offset is its entry id, the same on every process, and the session of a socket that dropped is kept in Redis (`<sessionKeyPrefix><pid>`, expiring after `maxDisconnectionDuration`). A client that comes back to any process gets its socket and the events it missed, read from the stream. A session is recovered once. An offset already trimmed by `maxLen` starts over.
- **The order of a process's own broadcasts:** with recovery on, a broadcast reaches the sockets of its own process once Redis gave its entry id (one round trip), and later broadcasts wait for it, so they keep their order. Without recovery, they go out at once, as with `RedisAdapter`.
- **`local` broadcasts** are appended too when they can be recovered, since their offset is their entry id: the other processes skip them, and only the sockets of the process that sent them recover them.
- Each process opens three connections: one for commands, one subscribed to the channels, and one blocked reading the stream. Several apps on one Redis need a different `key` and `streamName` each.

### Which Redis adapter

| | `RedisAdapter` | `RedisStreamsAdapter` |
|---|---|---|
| Broadcasts, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()` | publish and subscribe | a Redis stream |
| A process that loses Redis for a while | misses what was sent meanwhile | reads it when it comes back |
| Connection state recovery | on the process the client left, with sticky sessions | on any process |
| Redis | any version | 6.2 or later, or Valkey |
| Per broadcast | one `PUBLISH` | one `XADD`; with recovery, its own process waits for the reply |
| Connections per process | 2 | 3 |

Neither speaks the Node.js adapters' format: the servers of a cluster all run this package.

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
- For servers running `RedisStreamsAdapter`, use `Emitter::redisStreams($url, $key, $timeout, $tls, $options)` with the servers' `streamName` and `maxLen`: it appends what crosses the stream there, and its events, except volatile ones, get an offset, so clients recover them.
- The [emitter example](../examples/emitter) has a plain PHP endpoint that sends notifications to the open tabs.

## Limits

- With several processes on one port, clients must connect with `transports: ['websocket']`: polling needs every request of a session to reach the same process, which several processes on one port don't guarantee. Setting `'transports' => ['websocket']` on the server too refuses polling outright. Behind a proxy with sticky sessions and one port per process, polling works; see [Behind a reverse proxy](reverse-proxy.md).
- `fetchSockets()` without a callback and `allSockets()` read the current process.
- With `ChannelAdapter` or `RedisAdapter`, [connection state recovery](configuration.md#connection-state-recovery) recovers a client that comes back to the process it left, so it needs sticky sessions, such as one port per process behind a proxy. With recovery on, every broadcast that can be recovered goes through the namespace's channel, so every process gets it, whatever the rooms; events sent with the `Emitter` aren't recoverable. `RedisStreamsAdapter` recovers on any process, `Emitter` events included.
- `local` keeps any of the above in the current process: `$io->local->emit('reload')`, `$io->local->disconnectSockets()`.
