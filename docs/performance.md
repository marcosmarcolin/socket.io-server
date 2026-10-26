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
| 900 | `select()` | 22 KB | 33 MB | 0.25 s | 56,000 (14 / 44 ms) | 324,000 |
| 1,000 | `event` | 23 KB | 38 MB | 0.25 s | 59,000 (15 / 30 ms) | 346,000 |
| 5,000 | `event` | 24 KB | 129 MB | 0.71 s | 74,000 (61 / 116 ms) | 234,000 |
| 10,000 | `event` | 24 KB | 247 MB | 1.29 s | 67,000 (141 / 215 ms) | 206,000 |

- **Memory** grows linearly: about 24 KB per connection, both the PHP heap and the process's resident memory. Of that, about 14 KB are this package's objects (the Engine.IO session, the request, the client and the socket) and the rest is Workerman's connection and its buffers.
- **Echoes** are acknowledgements: every client sends 20, one after the other, all clients at once. The latency is under that full load, not of a quiet server.
- **Broadcasts** are 50 events of 100 bytes to every client. The time to emit them is nearly the time until all are delivered, so one process's CPU is the limit: more processes, with `ChannelAdapter` or `RedisAdapter`, multiply it (see [Several processes](multiple-processes.md)).

## Compared with the reference Node.js server

The same load test against the official `socket.io` 4.8.4 server (`tests/Load/node`), one process with the same handlers, on the same machine, in the same session:

| Clients | Server | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | Node.js | 100 MB | 62,000 (15 / 39 ms) | 518,000 |
| 1,000 | this server | 38 MB | 59,000 (15 / 30 ms) | 346,000 |
| 5,000 | Node.js | 201 MB | 89,000 (50 / 106 ms) | 572,000 |
| 5,000 | this server | 129 MB | 74,000 (61 / 116 ms) | 234,000 |
| 10,000 | Node.js | 288 MB | 91,000 (105 / 139 ms) | 520,000 |
| 10,000 | this server | 247 MB | 67,000 (141 / 215 ms) | 206,000 |

- **Memory is lower at every size.** This server starts smaller (about 13 MB against Node's 64 MB) and stays below it: 14% less at 10,000 clients.
- **Acknowledgements are on par** at 1,000 clients; Node answers about 20% more per second at 5,000 and 35% more at 10,000.
- **Broadcasts are where Node leads**, by 1.5 times at 1,000 clients up to 2.5 times at 10,000. Node's `emit()` returns once the frames are queued, and its event loop writes them afterwards; here each frame is written to its socket during the emit, one system call per client.

Runs vary by up to about 10% from one session to another, for both servers, so compare numbers from the same session.

```sh
SERVER=node CLIENTS=10000 tests/Load/run.sh
```

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
| PHP server | PHP 8.1.34 (the project's image), Workerman 5.2.3, `event` 3.1.4 for `LOOP=event`, `memory_limit=1G` |
| Node.js server | Node.js 24.21.0, `socket.io` 4.8.4 |
| Clients | Node.js 24.21.0, `socket.io-client` 4.8.4, WebSocket only, in their own container sharing the server's network |

Your numbers will differ; the shape won't.

## Measuring on your machine

```sh
tests/Load/run.sh                              # 500 clients on select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # the event extension, 10,000 clients
```

`CLIENTS`, `ROUNDS` (echoes per client), `MESSAGES` (broadcasts) and `SIZE` (bytes per broadcast) tune the run. Each run prints one line of JSON and a summary.
