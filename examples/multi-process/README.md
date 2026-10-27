# Several processes

Four processes share port 3000, and `ChannelAdapter` carries broadcasts between them through a `workerman/channel` server, so a message reaches the clients of every process.

## Run it

```sh
php examples/multi-process/start.php start
```

In this repository `workerman/channel` is a dev dependency; in your app, `composer require workerman/channel`.

Open http://localhost:3001 in a few tabs: each shows the process it landed on.

## Try

- Send a message from one tab: every tab gets it, whichever process it's on, with the process that sent it.

## How it works

- `new ChannelServer('127.0.0.1', 2206)` runs the channel server in a process of its own.
- A `tcp://` worker with `count = 4` and `reusePort`, so the kernel spreads connections, is handed to `$io->attach()`.
- The adapter connects in `workerStart`, since it needs each process's event loop: `$io->adapter(ChannelAdapter::factory(...))`.
- Without sticky sessions, the client must use `transports: ['websocket']`: polling requests of one session could land on different processes.

See [Several processes](../../docs/multiple-processes.md).

## Test

```sh
tests/Integration/run.sh example multi-process
```

[`multi-process.test.js`](../../tests/Integration/client/examples/multi-process.test.js)
