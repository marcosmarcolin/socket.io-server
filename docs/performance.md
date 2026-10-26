# Performance and limits

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
| 900 | `select()` | 22 KB | 34 MB | 0.22 s | 63,000 (13 / 30 ms) | 399,000 |
| 1,000 | `event` | 24 KB | 38 MB | 0.21 s | 56,000 (18 / 34 ms) | 396,000 |
| 5,000 | `event` | 24 KB | 132 MB | 0.81 s | 75,000 (61 / 120 ms) | 405,000 |
| 10,000 | `event` | 24 KB | 249 MB | 1.13 s | 63,000 (146 / 212 ms) | 385,000 |

- **Memory** grows linearly: about 24 KB per connection, both the PHP heap and the process's resident memory. Of that, about 14 KB are this package's objects (the Engine.IO session, the request, the client and the socket) and the rest is Workerman's connection and its buffers.
- **Echoes** are acknowledgements: every client sends 20, one after the other, all clients at once. The latency is under that full load, not of a quiet server.
- **Broadcasts** are 50 events of 100 bytes to every client, sent at once. One process's CPU is the limit: more processes, with `ChannelAdapter` or `RedisAdapter`, multiply it (see [Several processes](multiple-processes.md)). A single event to 10,000 clients (`MESSAGES=1`) reaches them all in about 75 ms, 129,000 deliveries per second.

## Compared with the reference Node.js server

The same load test against the official `socket.io` 4.8.4 server (`tests/Load/node`), one process with the same handlers, on the same machine, in the same session:

| Clients | Server | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | Node.js | 99 MB | 56,000 (16 / 34 ms) | 434,000 |
| 1,000 | this server | 38 MB | 56,000 (18 / 34 ms) | 396,000 |
| 5,000 | Node.js | 208 MB | 96,000 (48 / 86 ms) | 480,000 |
| 5,000 | this server | 132 MB | 75,000 (61 / 120 ms) | 405,000 |
| 10,000 | Node.js | 290 MB | 89,000 (105 / 194 ms) | 542,000 |
| 10,000 | this server | 249 MB | 63,000 (146 / 212 ms) | 385,000 |

- **Memory is lower at every size.** This server starts smaller (about 13 MB against Node's 64 MB) and stays below it: 14% less at 10,000 clients.
- **Acknowledgements are on par** at 1,000 clients; Node answers about 25% more per second at 5,000 and 40% more at 10,000.
- **Broadcasts are close:** Node delivers 1.1 times as many at 1,000 clients up to 1.4 times at 10,000. A single event to 10,000 clients: 129,000 deliveries per second here, 152,000 on Node. What remains is the interpreter: per delivery, this package runs about 0.6 µs of PHP where Node runs compiled JavaScript.

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
| 1,000 | `event` | 38 MB | 56,000 (18 / 34 ms) | 396,000 |
| 1,000 | Swoole | 47 MB | 68,000 (14 / 25 ms) | 444,000 |
| 5,000 | `event` | 132 MB | 75,000 (61 / 120 ms) | 405,000 |
| 5,000 | Swoole | 145 MB | 69,000 (72 / 136 ms) | 415,000 |
| 10,000 | `event` | 249 MB | 63,000 (146 / 212 ms) | 385,000 |
| 10,000 | Swoole | 267 MB | 60,000 (149 / 229 ms) | 418,000 |

**It is about as fast as `event`:** broadcasts 3 to 12% faster, echoes about the same, and about 7% more memory. Workerman uses only Swoole's loop and timers: the connections, HTTP and WebSocket stay Workerman's PHP code, and each callback runs in a new coroutine. Swoole's own speed comes from its server written in C, which this package doesn't use. Either loop does; Swoole fits when the application already runs on it for its coroutines.

## Where the time and the memory go

Profiled with the same server, one connection or one broadcast at a time:

- **The write system call is the largest cost of a delivery:** over loopback TCP, a `fwrite()` of one frame costs about 1.5 µs, Workerman's `send()` adds about 0.25 µs, and this package's own code about 0.6 µs (the rooms, the per-socket checks, the Engine.IO session and the transport). Workerman isn't the bottleneck.
- **So WebSocket writes are grouped:** the first frame a connection writes in a pass of the event loop goes out at once, and the next ones in the same pass are written together at its end, through one zero-delay timer for the whole server. A burst of 50 events to a client costs two writes instead of 50, which doubled broadcast deliveries per second; a single event still goes out at once. Grouping every frame, the first included, was tried: it slowed a single event to many clients by about 30%, since nothing went out until the whole emit loop ended.
- **The event listeners** were the largest repeated cost per connection: every session, client and socket holds an emitter, and storing each listener in its own nested array took about 1.2 KB per listener. They now live in flat arrays per emitter, which took the memory per connection from 30 KB to 24 KB and raised echoes per second by about 10%, with broadcasts unchanged.

## Test environment

All the numbers above come from one machine, with the server and the clients in Docker on the same host:

| | |
|---|---|
| Machine | Mac16,7 (MacBook Pro), Apple M4 Pro, 14 cores, 24 GB of memory, macOS 27.2 |
| Docker | Docker Desktop, engine 29.9.2; Linux VM with 14 CPUs and 8 GB, kernel 7.0.14 |
| PHP server | PHP 8.1.34, Workerman 5.2.3, `event` 3.1.4, and Swoole 6.1.10 (the `phpswoole/swoole` image), `memory_limit=1G` |
| Node.js server | Node.js 24.21.0, `socket.io` 4.8.4 |
| Clients | Node.js 24.21.0, `socket.io-client` 4.8.4, WebSocket only, in their own container sharing the server's network |

Your numbers will differ; the shape won't.

## Measuring on your machine

```sh
tests/Load/run.sh                              # 500 clients on select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # the event extension, 10,000 clients
LOOP=swoole CLIENTS=10000 tests/Load/run.sh    # Swoole's event loop
LOOP=event CLIENTS=10000 MESSAGES=1 tests/Load/run.sh   # a single event to every client
```

`CLIENTS`, `ROUNDS` (echoes per client), `MESSAGES` (broadcasts) and `SIZE` (bytes per broadcast) tune the run. Each run prints one line of JSON and a summary.
