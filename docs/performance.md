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

- **A `memory_limit` that fits the connections.** Each WebSocket connection takes about 30 KB, so the CLI's default 128 MB holds about 4,000. For 10,000, give it 512 MB or more (`php -d memory_limit=512M server.php start`).
- **Enough file descriptors:** `ulimit -n` above the number of connections, plus a few for the listening socket and files.

## Results

One process, WebSocket clients from the official `socket.io-client`, measured with `tests/Load/run.sh`:

| Clients | Event loop | Memory per connection | Total RSS | Connecting all | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|---|---|
| 900 | `select()` | 27 KB | 38 MB | 0.24 s | 53,000 (16 / 47 ms) | 250,000 |
| 1,000 | `event` | 29 KB | 43 MB | 0.21 s | 59,000 (15 / 33 ms) | 290,000 |
| 5,000 | `event` | 30 KB | 156 MB | 0.80 s | 65,000 (69 / 123 ms) | 220,000 |
| 10,000 | `event` | 30 KB | 298 MB | 1.38 s | 55,000 (142 / 298 ms) | 200,000 |

- **Memory** grows linearly: about 30 KB per connection, both the PHP heap and the process's resident memory.
- **Echoes** are acknowledgements: every client sends 20, one after the other, all clients at once. The latency is under that full load, not of a quiet server.
- **Broadcasts** are 50 events of 100 bytes to every client. The time to emit them is nearly the time until all are delivered, so one process's CPU is the limit: more processes, with `ChannelAdapter` or `RedisAdapter`, multiply it (see [Several processes](multiple-processes.md)).

Measured on an Apple M4 Pro, in Docker Desktop (14 CPUs, 8 GB), with PHP 8.1 and Node.js 24 on the same machine. Your numbers will differ; the shape won't.

## Compared with the reference Node.js server

The same load test against the official `socket.io` 4.8.4 server (`tests/Load/node`), one process with the same handlers, on the same machine, in the same session:

| Clients | Server | Total RSS | Echoes per second (p50 / p99) | Broadcast deliveries per second |
|---|---|---|---|---|
| 1,000 | Node.js | 98 MB | 55,000 (16 / 40 ms) | 477,000 |
| 1,000 | this server | 43 MB | 59,000 (15 / 33 ms) | 310,000 |
| 5,000 | Node.js | 201 MB | 71,000 (61 / 146 ms) | 438,000 |
| 5,000 | this server | 157 MB | 70,000 (66 / 131 ms) | 227,000 |
| 10,000 | Node.js | 288 MB | 84,000 (111 / 146 ms) | 460,000 |
| 10,000 | this server | 297 MB | 62,000 (136 / 229 ms) | 210,000 |

- **Memory is on par.** This server starts smaller (about 13 MB against Node's 64 MB), so it uses less up to a few thousand clients, and about the same at 10,000.
- **Acknowledgements are on par** up to 5,000 clients; at 10,000, Node answers about 35% more per second.
- **Broadcasts are where Node leads**, by 1.5 times at 1,000 clients up to 2.2 times at 10,000. Here the packet is encoded once, but each socket still builds its own Engine.IO packet and WebSocket frame, which is where this server spends the time: the place to optimize next.

The 10,000-client runs were repeated: the numbers moved by less than 10%.

```sh
SERVER=node CLIENTS=10000 tests/Load/run.sh
```

## Measuring on your machine

```sh
tests/Load/run.sh                              # 500 clients on select()
LOOP=event CLIENTS=10000 tests/Load/run.sh     # the event extension, 10,000 clients
```

`CLIENTS`, `ROUNDS` (echoes per client), `MESSAGES` (broadcasts) and `SIZE` (bytes per broadcast) tune the run. Each run prints one line of JSON and a summary.
