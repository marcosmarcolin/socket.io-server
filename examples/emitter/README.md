# Emitter

Sending to Socket.IO clients from your PHP app, such as a PHP-FPM request, which isn't a Socket.IO server: the `Emitter` publishes through Redis, and the server's `RedisAdapter` delivers.

## Run it

```sh
docker run --rm -p 6379:6379 redis:7.4.2-alpine
php examples/emitter/start.php start
```

Another Redis: `REDIS_URL=redis://:password@host:6379 php examples/emitter/start.php start`.

Open http://localhost:3001 in two tabs and send a notification from one.

## Try

- Send a notification: it goes as a plain HTTP `POST /notify`, not a Socket.IO emit, and every tab gets it over Socket.IO.

## How it works

- The page's HTTP worker plays your app. On `POST /notify` it runs one line, as any PHP-FPM request could:

  ```php
  Emitter::redis('redis://127.0.0.1:6379')->emit('notification', $data);
  ```

- The Socket.IO server only needs `RedisAdapter` to hear it.
- The `Emitter` also has `to()`, `except()`, `of()`, `socketsJoin()`, `disconnectSockets()` and `serverSideEmit()`; it has no acknowledgements.

See [Sending from your PHP app: the Emitter](../../docs/multiple-processes.md#sending-from-your-php-app-the-emitter).

## Test

```sh
tests/Integration/run.sh example emitter
```

`run.sh` starts a Redis next to it. [`emitter.test.js`](../../tests/Integration/client/examples/emitter.test.js)
