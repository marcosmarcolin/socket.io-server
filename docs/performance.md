# Performance and limits

How much one process holds, what it needs to get there, and how to measure it on your own machine.

## What a process needs

- **The `event` extension past about 1,000 connections.** Without it, Workerman uses PHP's `select()`, which handles at most 1,024 descriptors per process: past that, Workerman warns and new connections fail. With `event` installed, Workerman picks it by itself:

```sh
pecl install event
# event must load after sockets: name its ini so it sorts last
echo "extension=event.so" > "$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/zz-event.ini"
```

- **OPcache and its JIT, turned on for the CLI.** PHP leaves OPcache off on the command line (`opcache.enable_cli=0`), and a Workerman server is a CLI process, so without these settings it runs interpreted. With them, it answers about 20% more acknowledgements and delivers 20 to 30% more broadcasts (see [OPcache and the JIT](#opcache-and-the-jit)):

```ini
; PHP 8.1 to 8.4 also need zend_extension=opcache; PHP 8.5 builds it in
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
```

- **A `memory_limit` that fits the connections.** Each WebSocket connection takes about 25 KB, so the CLI's default 128 MB holds about 5,000. For 10,000, give it 512 MB or more (`php -d memory_limit=512M server.php start`).
- **Enough file descriptors:** `ulimit -n` above the number of connections, plus a few for the listening socket and files.

## Results

One process on PHP 8.1 without OPcache, WebSocket clients from the official `socket.io-client`, measured with `tests/Load/run.sh`, the median of three runs:

| Clients | Event loop | Memory per connection | Total RSS | Connecting all | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|---|---|
| 900 | `select()` | 23 KB | 35 MB | 0.22 s | 65,000 (12 / 29 ms) | 469,000 |
| 1,000 | `event` | 24 KB | 40 MB | 0.23 s | 64,000 (15 / 29 ms) | 422,000 |
| 5,000 | `event` | 25 KB | 140 MB | 0.66 s | 78,000 (59 / 113 ms) | 420,000 |
| 10,000 | `event` | 25 KB | 262 MB | 1.15 s | 72,000 (122 / 202 ms) | 400,000 |

- **Memory** grows linearly: about 25 KB per connection, both the PHP heap and the process's resident memory. Of that, about 14 KB are this package's objects (the Engine.IO session, the request, the client and the socket) and the rest is Workerman's connection and its buffers.
- **Echoes** are acknowledgements: every client sends 20, one after the other, all clients at once. The latency is under that full load, not of a quiet server.
- **Broadcasts** are 50 events of 100 bytes to every client, sent at once. One process's CPU is the limit: more processes, with `ChannelAdapter`, `RedisAdapter` or `RedisStreamsAdapter`, multiply it (see [Several processes](multiple-processes.md)). A single event to 10,000 clients (`MESSAGES=1`) reaches them all in about 75 ms, 133,000 deliveries per second.

## OPcache and the JIT

The same load test with OPcache and its tracing JIT on (`JIT=1`), and on PHP 8.5, in the same session as the tables above:

| Clients | PHP | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | 8.1 | 40 MB | 64,000 (15 / 29 ms) | 422,000 |
| 1,000 | 8.1 with the JIT | 48 MB | 64,000 (14 / 27 ms) | 495,000 |
| 1,000 | 8.5 | 42 MB | 65,000 (14 / 28 ms) | 461,000 |
| 1,000 | 8.5 with the JIT | 49 MB | 62,000 (15 / 36 ms) | 538,000 |
| 5,000 | 8.1 | 140 MB | 78,000 (59 / 113 ms) | 420,000 |
| 5,000 | 8.1 with the JIT | 149 MB | 94,000 (49 / 95 ms) | 503,000 |
| 5,000 | 8.5 | 135 MB | 82,000 (56 / 105 ms) | 478,000 |
| 5,000 | 8.5 with the JIT | 144 MB | 96,000 (47 / 94 ms) | 546,000 |
| 10,000 | 8.1 | 262 MB | 72,000 (122 / 202 ms) | 400,000 |
| 10,000 | 8.1 with the JIT | 275 MB | 85,000 (110 / 160 ms) | 478,000 |
| 10,000 | 8.5 | 249 MB | 71,000 (126 / 213 ms) | 417,000 |
| 10,000 | 8.5 with the JIT | 262 MB | 87,000 (109 / 182 ms) | 486,000 |

- **The JIT is the gain:** about 20% more echoes per second at 5,000 and 10,000 clients, and 17 to 30% more broadcast deliveries at every size, on either version. At 1,000 clients echoes don't move: they vary more from run to run than the JIT changes them.
- **It costs 8 to 13 MB, once:** OPcache's shared memory and the compiled code (about 0.4 MB of the JIT's buffer), not memory per connection.
- **PHP 8.5 alone changes little:** broadcasts 4 to 14% faster and echoes the same, within the run-to-run variation. It takes about 1.6 KB less per connection (24 KB against 25 KB), so 10,000 clients take 13 MB less.
- **A single event to many clients isn't faster:** 138,000 deliveries per second to 10,000 clients with the JIT, 133,000 without. That path is the write system call per client, which the JIT doesn't touch (see [Where the time and the memory go](#where-the-time-and-the-memory-go)).

## Compared with the reference Node.js server

The same load test against the official `socket.io` 4.8.4 server (`tests/Load/node`), one process with the same handlers, on the same machine, in the same session:

| Clients | Server | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | Node.js | 98 MB | 67,000 (13 / 33 ms) | 535,000 |
| 1,000 | this server, PHP 8.1 | 40 MB | 64,000 (15 / 29 ms) | 422,000 |
| 1,000 | this server, PHP 8.5 with the JIT | 49 MB | 62,000 (15 / 36 ms) | 538,000 |
| 5,000 | Node.js | 207 MB | 95,000 (49 / 90 ms) | 596,000 |
| 5,000 | this server, PHP 8.1 | 140 MB | 78,000 (59 / 113 ms) | 420,000 |
| 5,000 | this server, PHP 8.5 with the JIT | 144 MB | 96,000 (47 / 94 ms) | 546,000 |
| 10,000 | Node.js | 276 MB | 93,000 (104 / 136 ms) | 495,000 |
| 10,000 | this server, PHP 8.1 | 262 MB | 72,000 (122 / 202 ms) | 400,000 |
| 10,000 | this server, PHP 8.5 with the JIT | 262 MB | 87,000 (109 / 182 ms) | 486,000 |

- **Memory is lower at every size.** This server starts smaller (about 13 MB against Node's 64 MB) and stays below it: 5% less at 10,000 clients. On PHP 8.1 with the JIT, 10,000 clients take as much as on Node.
- **With the JIT, the two are close:** echoes on par at 5,000 clients and 7% fewer at 10,000, broadcasts on par at 1,000 and 10,000 and 8% fewer at 5,000. Without it, Node answers 21 to 29% more acknowledgements from 5,000 clients and delivers 1.2 to 1.4 times as many broadcasts: Node always compiles its JavaScript, and the CLI runs PHP interpreted unless told otherwise.
- **A single event to 10,000 clients:** 133,000 deliveries per second here (138,000 with the JIT), 154,000 on Node.

Runs vary by up to about 10% from one session to another, for both servers, and up to 15% within one at 1,000 clients, so compare numbers from the same session.

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

The extension is installed with `pecl install swoole`; Swoole 6.2 needs PHP 8.2, and 6.1 is the last line for PHP 8.1. The same load test on PHP 8.1 without OPcache, in the same session as the tables above:

| Clients | Event loop | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | `event` | 40 MB | 64,000 (15 / 29 ms) | 422,000 |
| 1,000 | Swoole | 49 MB | 61,000 (15 / 30 ms) | 432,000 |
| 5,000 | `event` | 140 MB | 78,000 (59 / 113 ms) | 420,000 |
| 5,000 | Swoole | 151 MB | 68,000 (67 / 144 ms) | 396,000 |
| 10,000 | `event` | 262 MB | 72,000 (122 / 202 ms) | 400,000 |
| 10,000 | Swoole | 279 MB | 60,000 (155 / 233 ms) | 407,000 |

**It is about as fast as `event`:** broadcasts within 6% either way, echoes 5 to 16% fewer, and 6 to 9 MB more memory, more with fewer clients. Workerman uses only Swoole's loop and timers: the connections, HTTP and WebSocket stay Workerman's PHP code, and each callback runs in a new coroutine. Swoole's own speed comes from its server written in C, which this package doesn't use. Either loop does; Swoole fits when the application already runs on it for its coroutines.

## Long runs: the soak test

A server runs for days, so memory must stay flat while clients come and go. `tests/Soak/run.sh` keeps thousands of clients connecting and leaving for as long as asked: each lives 10 to 60 seconds over WebSocket, polling or the upgrade, echoes with acknowledgements, sends binary, joins and leaves rooms, and 30% drop their transport on the way to recover it. A numbered broadcast goes out every 200 ms, and every client checks it for lost, repeated or reordered messages. The server samples what it holds every 30 seconds.

20 minutes, 2,000 clients (about 1,850 connected at a time), `event`, recovery on with a 30-second window:

| | |
|---|---|
| Connections opened | 81,615 (about 68 a second) |
| Transports dropped and recovered | 17,615 dropped, 17,560 recovered, none failed (the rest ended their life first) |
| Numbered messages received | 11,012,036: none lost, repeated or reordered |
| Acknowledgements | 441,559 echoes and 132,192 binary answers, all right; 118 timed out, from clients that left while waiting |
| Heap while running | 53.1 to 54.8 MB, flat from the first sample to the last |
| Heap once the clients left | 7.8 MB, one control socket left |

Version 0.5.1 and earlier leaked about 7 KB per closed connection: the same run grew from 66 MB to 812 MB in 17 minutes, and the CPU reached 100%. The resident memory (RSS) stays at its peak after the clients leave, as PHP keeps freed memory for reuse instead of returning it to the system.

```sh
DURATION=1200 CLIENTS=2000 tests/Soak/run.sh
```

## Where the time and the memory go

Profiled with the same server, one connection or one broadcast at a time:

- **The write system call is the largest cost of a delivery:** over loopback TCP, a `fwrite()` of one frame costs about 1.5 µs, Workerman's `send()` adds about 0.25 µs, and this package's own code about 0.6 µs, interpreted (the rooms, the per-socket checks, the Engine.IO session and the transport). Workerman isn't the bottleneck.
- **So WebSocket writes are grouped:** the first frame a connection writes in a pass of the event loop goes out at once, and the next ones in the same pass are written together at its end, through one zero-delay timer for the whole server. A burst of 50 events to a client costs two writes instead of 50, which doubled broadcast deliveries per second; a single event still goes out at once. Grouping every frame, the first included, was tried: it slowed a single event to many clients by about 30%, since nothing went out until the whole emit loop ended.
- **The event listeners** were the largest repeated cost per connection: every session, client and socket holds an emitter, and storing each listener in its own nested array took about 1.2 KB per listener. They now live in flat arrays per emitter, which took the memory per connection from 30 KB to 24 KB and raised echoes per second by about 10%, with broadcasts unchanged.

## Test environment

All the numbers above, except the soak test's, come from one session on one machine, with the server and the clients in Docker on the same host:

| | |
|---|---|
| Machine | Mac16,7 (MacBook Pro), Apple M4 Pro, 14 cores, 24 GB of memory, macOS 27.3 |
| Docker | Docker Desktop, engine 29.9.3; Linux VM with 14 CPUs and 8 GB, kernel 7.0.14 |
| PHP server | PHP 8.1.34 and 8.5.12 (the official `php:*-cli` images), Workerman 5.2.3, `event` 3.1.4, and Swoole 6.1.10 (the `phpswoole/swoole` image), `memory_limit=1G`; with the JIT, `opcache.enable_cli=1`, `opcache.jit=tracing` and `opcache.jit_buffer_size=128M` |
| Node.js server | Node.js 24.21.0, `socket.io` 4.8.4 |
| Clients | Node.js 24.21.0, `socket.io-client` 4.8.4, WebSocket only, in their own container sharing the server's network |

Your numbers will differ; the shape won't.

## Measuring on your machine

```sh
tests/Load/run.sh                              # 500 clients on select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # the event extension, 10,000 clients
LOOP=swoole CLIENTS=10000 tests/Load/run.sh    # Swoole's event loop
LOOP=event JIT=1 CLIENTS=10000 tests/Load/run.sh                 # with OPcache and its tracing JIT
LOOP=event PHP_VERSION=8.5 JIT=1 CLIENTS=10000 tests/Load/run.sh # on PHP 8.5
LOOP=event CLIENTS=10000 MESSAGES=1 tests/Load/run.sh   # a single event to every client
```

`CLIENTS`, `ROUNDS` (echoes per client), `MESSAGES` (broadcasts) and `SIZE` (bytes per broadcast) tune the run. `PHP_VERSION` (8.1 by default) and `EVENT_VERSION` (3.1.4) pick the image of `tests/Load/Dockerfile`, and `JIT=1` turns OPcache and its JIT on, with `LOOP` set. Each run prints one line of JSON, with the PHP version and whether the JIT really ran (from `opcache_get_status()`), and a summary.
