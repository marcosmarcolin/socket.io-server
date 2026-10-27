# Redis

Four processes joined by Redis with `RedisAdapter`. The same code runs on several machines pointed at one Redis.

## Run it

```sh
docker run --rm -p 6379:6379 redis:7.4.2-alpine
php examples/redis/start.php start
```

Another Redis: `REDIS_URL=redis://:password@host:6379 php examples/redis/start.php start` (`rediss://` for TLS).

Open http://localhost:3001 in a few tabs: each shows the process it landed on.

## Try

- Send a message from one tab: every tab gets it, with the process that sent it.
- Stop Redis and start it again: the adapter reconnects by itself.

## How it works

- `$io->adapter(RedisAdapter::factory($url))` in `workerStart`. The adapter has its own Redis client on Workerman, so it needs no extension or package.
- A `tcp://` worker with `count = 4` and `reusePort`, handed to `$io->attach()`.
- Without sticky sessions, the client must use `transports: ['websocket']`.

See [Redis](../../docs/multiple-processes.md#redis).

## Test

```sh
tests/Integration/run.sh example redis
```

`run.sh` starts a Redis next to it. [`redis.test.js`](../../tests/Integration/client/examples/redis.test.js)
