# Development

Everything runs in Docker; PHP and Composer are not needed on the host.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) and PHPStan (level max)
tests/Compliance/engine-io.sh                  # the official Engine.IO v4 test suite
tests/Compliance/socket-io.sh                  # the official Socket.IO v5 test suite
tests/Integration/run.sh                       # the official socket.io-client against this server
tests/Integration/run.sh multi-process         # the same over two processes and a channel server
tests/Integration/run.sh multi-process-redis   # the same two processes, joined by Redis
tests/Integration/run.sh examples              # every example, each against its test
tests/Load/run.sh                              # the load test; see docs/performance.md
LOOP=swoole tests/Integration/run.sh           # any of the scripts above on another event loop
```

`LOOP=select`, `event` or `swoole` runs the server of the compliance, integration and load scripts on that Workerman event loop, in the image of `tests/Load/Dockerfile`, which has the `event` extension, or of `tests/Load/swoole.Dockerfile`, on the Swoole project's PHP image. CI runs the suites and the integration tests on Swoole too.

## Secret scanning

[gitleaks](https://github.com/gitleaks/gitleaks) runs in Docker and blocks commits that add secrets. Enable the hook once per clone:

```sh
git config core.hooksPath .githooks
```

Docker must be running to commit. CI also scans the whole history on every pull request.
