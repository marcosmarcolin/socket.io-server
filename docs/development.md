# Development

**English** · [Português (Brasil)](development.pt-BR.md)

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
```

## Secret scanning

[gitleaks](https://github.com/gitleaks/gitleaks) runs in Docker and blocks commits that add secrets. Enable the hook once per clone:

```sh
git config core.hooksPath .githooks
```

Docker must be running to commit. CI also scans the whole history on every pull request.
