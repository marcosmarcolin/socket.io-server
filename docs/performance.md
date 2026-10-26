# Performance and limits

**English** · [Português (Brasil)](performance.pt-BR.md)

How much one process holds, what it needs to get there, and how to measure it on your own machine.

## What a process needs

- **The `event` extension past about 1,000 connections.** Without it, Workerman uses PHP's `select()`, which handles at most 1,024 descriptors per process: past that, Workerman warns and new connections fail. With `event` installed, Workerman picks it by itself:

```sh
pecl install event
# event must load after sockets: name its ini so it sorts last
echo "extension=event.so" > "$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/zz-event.ini"
```

- **A `memory_limit` that fits the connections.** Each WebSocket connection takes about 24 KB, so the CLI's default 128 MB holds a little under 5,000. For 10,000, give it 512 MB or more (`php -d memory_limit=512M server.php start`).
- **Enough file descriptors:** `ulimit -n` above the number of connections, plus a few for the listening socket and files.

## Results

One process, WebSocket clients from the official `socket.io-client`, measured with `tests/Load/run.sh`:

| Clients | Event loop | Memory per connection | Total RSS | Connecting all | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|---|---|
| 900 | `select()` | 22 KB | 36 MB | 0.22 s | 54,000 (14 / 34 ms) | 343,000 |
| 1,000 | `event` | 23 KB | 40 MB | 0.21 s | 64,000 (14 / 27 ms) | 369,000 |
| 5,000 | `event` | 24 KB | 133 MB | 0.88 s | 70,000 (63 / 125 ms) | 230,000 |
| 10,000 | `event` | 24 KB | 249 MB | 1.24 s | 65,000 (135 / 222 ms) | 200,000 |

- **Memory** grows linearly: about 24 KB per connection, both the PHP heap and the process's resident memory. Of that, about 14 KB are this package's objects (the Engine.IO session, the request, the client and the socket) and the rest is Workerman's connection and its buffers.
- **Echoes** are acknowledgements: every client sends 20, one after the other, all clients at once. The latency is under that full load, not of a quiet server.
- **Broadcasts** are 50 events of 100 bytes to every client. The time to emit them is nearly the time until all are delivered, so one process's CPU is the limit: more processes, with `ChannelAdapter` or `RedisAdapter`, multiply it (see [Several processes](multiple-processes.md)).

## Compared with the reference Node.js server

The same load test against the official `socket.io` 4.8.4 server (`tests/Load/node`), one process with the same handlers, on the same machine, in the same session:

| Clients | Server | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | Node.js | 99 MB | 61,000 (14 / 30 ms) | 493,000 |
| 1,000 | this server | 40 MB | 64,000 (14 / 27 ms) | 369,000 |
| 5,000 | Node.js | 194 MB | 83,000 (52 / 106 ms) | 502,000 |
| 5,000 | this server | 133 MB | 70,000 (63 / 125 ms) | 230,000 |
| 10,000 | Node.js | 289 MB | 91,000 (106 / 156 ms) | 475,000 |
| 10,000 | this server | 249 MB | 65,000 (135 / 222 ms) | 200,000 |

- **Memory is lower at every size.** This server starts smaller (about 13 MB against Node's 64 MB) and stays below it: 14% less at 10,000 clients.
- **Acknowledgements are on par** at 1,000 clients; Node answers about 20% more per second at 5,000 and 40% more at 10,000.
- **Broadcasts are where Node leads**, by 1.3 times at 1,000 clients up to 2.4 times at 10,000. Node's `emit()` returns once the frames are queued, and its event loop writes them afterwards; here each frame is written to its socket during the emit, one system call per client.

Runs vary by up to about 10% from one session to another, for both servers, so compare numbers from the same session.

```sh
SERVER=node CLIENTS=10000 tests/Load/run.sh
```

## On Swoole's event loop

Workerman 5 can also run on the event loop of the [Swoole](https://github.com/swoole/swoole-src) extension. This server works on it: the official suites and the integration tests pass on it too, in CI. Choose it before `Worker::runAll()`:

```php
use Workerman\Events\Swoole;
use Workerman\Worker;

Worker::$eventLoopClass = Swoole::class;
```

The extension is installed with `pecl install swoole`; Swoole 6.2 needs PHP 8.2, and 6.1 is the last line for PHP 8.1. The same load test, in the same session as the tables above:

| Clients | Event loop | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | `event` | 40 MB | 64,000 (14 / 27 ms) | 369,000 |
| 1,000 | Swoole | 44 MB | 58,000 (15 / 34 ms) | 269,000 |
| 5,000 | `event` | 133 MB | 70,000 (63 / 125 ms) | 230,000 |
| 5,000 | Swoole | 141 MB | 65,000 (71 / 127 ms) | 230,000 |
| 10,000 | `event` | 249 MB | 65,000 (135 / 222 ms) | 200,000 |
| 10,000 | Swoole | 262 MB | 58,000 (170 / 231 ms) | 204,000 |

**It isn't faster than `event`:** about 10% fewer echoes per second, the same broadcasts past 5,000 clients, and about 5% more memory. Workerman uses only Swoole's loop and timers: the connections, HTTP and WebSocket stay Workerman's PHP code, and each callback runs in a new coroutine. Swoole's own speed comes from its server written in C, which this package doesn't use. Prefer `event`, unless the application already runs on Swoole for its coroutines.

## Where the time and the memory go

Profiled with the same server, one connection or one broadcast at a time:

- **A broadcast delivery** costs about 1.2 µs in this package (the rooms, the per-socket checks and handing the encoded packet to the transport) and about 3.5 µs in Workerman's `send()` and the kernel's write. Building the WebSocket frame takes 0.03 µs: writing frames directly, skipping Workerman's protocol, was tried and gained nothing.
- **The event listeners** were the largest repeated cost per connection: every session, client and socket holds an emitter, and storing each listener in its own nested array took about 1.2 KB per listener. They now live in flat arrays per emitter, which took the memory per connection from 30 KB to 24 KB and raised echoes per second by about 10%, with broadcasts unchanged.

## Test environment

All the numbers above come from one machine, with the server and the clients in Docker on the same host:

| | |
|---|---|
| Machine | Mac16,7 (MacBook Pro), Apple M4 Pro, 14 cores, 24 GB of memory, macOS 27.2 |
| Docker | Docker Desktop, engine 29.9.2; Linux VM with 14 CPUs and 8 GB, kernel 7.0.14 |
| PHP server | PHP 8.1.34, Workerman 5.2.3, `event` 3.1.4 and Swoole 6.1.11 (`tests/Load/Dockerfile`), `memory_limit=1G` |
| Node.js server | Node.js 24.21.0, `socket.io` 4.8.4 |
| Clients | Node.js 24.21.0, `socket.io-client` 4.8.4, WebSocket only, in their own container sharing the server's network |

Your numbers will differ; the shape won't.

## Measuring on your machine

```sh
tests/Load/run.sh                              # 500 clients on select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # the event extension, 10,000 clients
LOOP=swoole CLIENTS=10000 tests/Load/run.sh    # Swoole's event loop
```

`CLIENTS`, `ROUNDS` (echoes per client), `MESSAGES` (broadcasts) and `SIZE` (bytes per broadcast) tune the run. Each run prints one line of JSON and a summary.
