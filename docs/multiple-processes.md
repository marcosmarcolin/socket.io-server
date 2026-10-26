# Several processes

**English** · [Português (Brasil)](multiple-processes.pt-BR.md)

Broadcasts reach the other processes through an adapter, set in `workerStart` on every process:

- `ChannelAdapter`, on a [workerman/channel](https://github.com/walkor/channel) server: nothing else to run, good for one machine.
- `RedisAdapter`, on a Redis server: for several machines, or when you already run Redis. It needs no extra package.

Both work the same way: each broadcast is encoded once and published once, and every process delivers it to its own sockets.

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
    url: 'redis://127.0.0.1:6379',   // redis://[[user]:password@]host[:port]
    key: 'socket.io',                // the first part of every channel name
    onError: null,                   // fn (Throwable $error): connection failures and Redis errors
);
```

- The URL defaults to `redis://127.0.0.1:6379`. Reserved characters in the user or password are percent-encoded; a database number is accepted and ignored, as publish and subscribe channels are shared by every database. TLS (`rediss://`) is not supported yet.
- Servers of different apps sharing one Redis need different `key`s, or they get each other's broadcasts.
- When Redis is unreachable, the adapter retries every second and reports the failure once to `onError` (`Worker::log` by default). Up to 1,000 publishes wait for the connection; messages published while a process was disconnected are lost, as always with Redis pub/sub.

## Limits

- Clients must connect with `transports: ['websocket']`: polling needs every request of a session to reach the same process, which several processes on one port don't guarantee. Setting `'transports' => ['websocket']` on the server too refuses polling outright. Across machines, a load balancer with sticky sessions lets polling work.
- Only broadcasts cross processes (`emit`, `to`, `except`, `broadcast`). `fetchSockets`, `allSockets`, `socketsJoin`, `socketsLeave` and `disconnectSockets` act on the current process.
- Broadcasts with an acknowledgement (`timeout()->emit(..., $callback)`) don't cross processes yet: both adapters throw.
