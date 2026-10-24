# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first release. Its version number is chosen when it is tagged.

### Added

- **Socket.IO v4 server for PHP 8.1+ on Workerman 5.2**, for `socket.io-client` v3 and v4: the Engine.IO v4 and Socket.IO v5 protocols.
- **Transports**: HTTP long-polling and WebSocket on one port, with the upgrade from polling to WebSocket; text and binary WebSocket frames.
- **Engine.IO**: request validation with the reference's error codes, heartbeat, `maxPayload` (413 on larger bodies), `upgradeTimeout`, CORS (`'*'`, a list of origins, or off) with the preflight.
- **Server API** in phpsocket.io's shape: `SocketIO`, namespaces with `of()`, `connection` and `disconnect` events, `emit`/`send`, `$socket->data`, `handshake` (with `auth`), `join`/`leave`/`rooms`, `disconnect(bool $close)`.
- **Middlewares** with `use()`, which may call `$next` asynchronously; `ConnectionError` sends a message and data to the client's `connect_error`.
- **Acknowledgements** both ways, and `timeout()` with a `TimeoutException`.
- **Broadcasting**: `to`/`in`/`except`, `$socket->broadcast`, `volatile`, and `allSockets`, `fetchSockets`, `socketsJoin`, `socketsLeave`, `disconnectSockets`.
- **Binary data** with `Binary`, anywhere in the data, both ways.
- **Several processes**: `ChannelAdapter` broadcasts across processes through workerman/channel. It keeps phpsocket.io 3.x's fixes and publishes each broadcast once, so a socket in several targeted rooms gets it once.
- **Workerman integration**: `new SocketIO($port, $options)`, or `attach()` on your own worker for another address, several processes or TLS; `workerStart`/`workerStop` events.
- **Documentation**: README with an API reference, in English and Brazilian Portuguese; six runnable examples; a migration guide from phpsocket.io.

### Verified

- The official Engine.IO v4 (25 tests) and Socket.IO v5 (32 tests) compliance suites pass in CI.
- The official `socket.io-client` 4.8.4 passes 40 integration tests over polling, WebSocket and the upgrade; 7 more over two processes, with a scale check of 4,000 deliveries; and each example's test.
- PHPUnit, PHPStan at level max and phpcs (PSR-12) run on PHP 8.1 to 8.5.

### Known limitations

- Out of scope: `socket.io-client` 1.x/2.x (use workerman/phpsocket.io), WebTransport, JSONP, custom parsers, dynamic namespaces, connection state recovery, a Redis adapter.
- Broadcast acknowledgements are not supported, and `compress()` has no effect.
- Across processes, only broadcasts travel; the socket helpers (`fetchSockets`, `socketsJoin`...) act on the current process.
- With several processes on one port, clients must use `transports: ['websocket']`.

[Unreleased]: https://github.com/marcosmarcolin/socket.io-server/commits/main
