# socket.io-server

**English** · [Português (Brasil)](README.pt-BR.md)

A [Socket.IO](https://socket.io) v4 server for PHP, built on [Workerman](https://github.com/walkor/workerman).

> **Work in progress.** Not usable yet: the Engine.IO layer is being built, and there is no server to run yet.

## Scope

- Speaks the Engine.IO v4 and Socket.IO v5 protocols, for `socket.io-client` v3 and v4.
- Transports: HTTP long-polling and WebSocket, with the upgrade from polling to WebSocket.
- PHP >= 8.1 and Workerman 5. No framework required.

For `socket.io-client` v1 and v2, use [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Status

| Step | State |
|---|---|
| Engine.IO packet parser | Done |
| Engine.IO session: heartbeat, write buffer, polling and WebSocket transports, upgrade | Done |
| Engine.IO server on Workerman, checked by the official Engine.IO v4 test suite | Next |
| Socket.IO parser, namespaces, rooms, acks and broadcast | Planned |
| Multi-process broadcast with `workerman/channel` | Planned |

## Development

Everything runs in Docker; PHP and Composer are not needed on the host.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) and PHPStan (level max)
```

### Secret scanning

[gitleaks](https://github.com/gitleaks/gitleaks) runs in Docker and blocks commits that add secrets. Enable the hook once per clone:

```sh
git config core.hooksPath .githooks
```

Docker must be running to commit. CI also scans the whole history on every pull request.

## License

MIT
