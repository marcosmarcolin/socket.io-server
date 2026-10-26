# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`RedisAdapter`**: broadcasts across processes and machines through Redis publish and subscribe, set with `$io->adapter(RedisAdapter::factory('redis://127.0.0.1:6379'))` in `workerStart`. The URL (with an optional user and password), the channel key and the error handler are configurable, and every argument has a default. It has its own Redis client on Workerman, so it needs no extra package, and it reconnects and subscribes again by itself.
- **The `redis` example**: four processes joined by Redis.
- **The cluster protocol** in `ChannelAdapter` and `RedisAdapter`, as the reference's `ClusterAdapterWithHeartbeat`: servers count each other with heartbeats, and `socketsJoin()`, `socketsLeave()` and `disconnectSockets()` now apply on every server.
- **`serverSideEmit()`** on `SocketIO` and `Nsp`: events between servers, received with `on()`, with an optional callback that gets one response per other server or a `TimeoutException` after 5 seconds.
- **Broadcasts with an acknowledgement cross processes** in `ChannelAdapter` and `RedisAdapter`: the callback of `$io->timeout(5000)->emit('ask', $callback)` gets the answers of the sockets of every server. They used to throw.
- **`RemoteSocket::timeout()`**: `$remote->timeout(5000)->emit('ask', fn (?Throwable $error, mixed $answer) => ...)` gets the socket's answer, as `Socket::timeout()` does.
- **`fetchSockets()` with a callback** reaches every server: `$io->in('room')->fetchSockets(fn (?Throwable $error, array $sockets) => ...)` gets this process's `Socket` objects and a `RemoteSocket` (`id`, `handshake`, `rooms`, `data`, `emit()`, `join()`, `leave()`, `disconnect()`) for each socket elsewhere, or a `TimeoutException` with the sockets so far. Without a callback, `fetchSockets()` still returns this process's sockets at once.
- **`BroadcastOptions::$timeout`**, `Adapter::fetchClusterSockets()`, `Adapter::serverCount()` and `Adapter::serverSideEmit()`; `InMemoryAdapter` counts one server, answers `fetchClusterSockets()` at once with its own sockets, and throws on `serverSideEmit()`.
- **`Socket::use()`**: middlewares for the events a socket receives, as the reference's `socket.use()`. `$next($error)` stops the event and emits `error` on the socket.
- **Engine events** on `$io->engine`, as the reference's: `initial_headers` and `headers` let listeners change a session's response headers through `ResponseHeaders`, such as to set a cookie, over polling and in the WebSocket 101; `connection_error` reports each refused request with its code and message.
- **The `local` flag**: `$io->local`, `$nsp->local`, `$socket->local` and `->local` on a broadcast keep a broadcast, the socket helpers or `fetchSockets()` in the current process, whatever the adapter, as in the reference.
- **`fetchSockets()`, `socketsJoin()`, `socketsLeave()` and `disconnectSockets()` on `SocketIO`**, acting on the main namespace, as the reference's `io` has them.
- **The `cookie` option**: `'cookie' => true` makes the polling handshake set `io=<session id>`, as the reference does, for load balancers with sticky sessions; an array changes its attributes.
- **The guide "Behind a reverse proxy"** (`docs/reverse-proxy.md`): nginx and HAProxy configurations, with TLS and sticky sessions for polling over several processes, one port each.
- **`Emitter`**: sends to the clients from any PHP code that isn't a server, such as a PHP-FPM request, as the reference's emitter packages: `Emitter::redis($url)->to('user:42')->emit('event', $data)`, with `of()`, `except()`, `volatile`, the socket helpers and `serverSideEmit()`. It uses blocking I/O, so it needs no event loop.
- **The `emitter` example**: a plain PHP endpoint sending notifications to the open tabs through the `Emitter`.
- **The `dashboard` example**: live dashboards, where a timer started in `workerStart` pushes new numbers every few seconds to the viewers of each dashboard, as a room.

### Changed

- `$io->on()` registers any event but `workerStart` and `workerStop` on the main namespace, as the reference does, so `$io->on('event')` receives `serverSideEmit()`.
- The messages `ChannelAdapter` publishes have a new shape, with a `uid` and a `type`: during an upgrade, processes on the old and new versions don't hear each other's broadcasts.
- The cross-process logic of `ChannelAdapter` moved to the abstract `PubSubAdapter`, which `ChannelAdapter` and `RedisAdapter` extend. Adapters for other brokers can extend it with their own `PubSub`. `ChannelAdapter::factory()` and `new ChannelAdapter($nsp, $pubSub)` work as before.

## [0.2.0] - 2026-10-25

### Added

- **`onAny()` and `onAnyOutgoing()`** on `Socket`, with `prependAny()`, `offAny()`, `listenersAny()` and their outgoing counterparts, as in the reference. Outgoing listeners see emits and broadcasts, including broadcasts from other processes through `ChannelAdapter`.
- **The `allowUpgrades` option** (`true` by default): with `false`, the handshake offers no upgrade, as in the reference.

### Changed

- For adapters that extend `InMemoryAdapter`: the protected `deliver()` takes a third argument, a `Closure` returning the event's name and arguments, which it calls only for sockets with `onAnyOutgoing()` listeners.

## [0.1.0] - 2026-10-25

The first release.

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
- **CORS with credentials**: `cors.credentials` lets browsers send cookies cross-origin, and `cors.origin` also takes a `Closure` that decides per request, such as "the page's host, on any port". `'*'` with credentials throws, since browsers reject it.
- **The `transports` option** limits the transports clients may use, such as `['websocket']` for several processes without sticky sessions. Others are refused as unknown, and the handshake offers only allowed upgrades, as in the reference.
- **The `allowRequest` option** accepts or refuses each handshake before the session opens, as in the reference: `true` accepts, `false` or a message refuses with code 4 (`Forbidden`), HTTP 403 over polling and 400 over WebSocket.
- **Broadcast acknowledgements**: after `timeout()`, `$io->to('room')->timeout(5000)->emit('x', $callback)` gives the callback one answer per socket, or a `TimeoutException` with the answers so far, as in the reference. Within one process; `ChannelAdapter` throws.
- **`$io->close()`** shuts the server down as the reference does: every socket leaves with `server shutting down`, the adapters close, every connection closes at once, and the worker stops accepting connections in this process.
- **Documentation**: README with an API reference, in English and Brazilian Portuguese; six runnable examples; a migration guide from phpsocket.io.

### Verified

- The official Engine.IO v4 (25 tests) and Socket.IO v5 (32 tests) compliance suites pass in CI.
- The official `socket.io-client` 4.8.4 passes 40 integration tests over polling, WebSocket and the upgrade; 7 more over two processes, with a scale check of 4,000 deliveries; and each example's test.
- PHPUnit, PHPStan at level max and phpcs (PSR-12) run on PHP 8.1 to 8.5.

### Known limitations

- Out of scope: `socket.io-client` 1.x/2.x (use workerman/phpsocket.io), WebTransport, JSONP, custom parsers, dynamic namespaces, connection state recovery, a Redis adapter.
- Broadcast acknowledgements don't cross processes yet (`ChannelAdapter` throws), and `compress()` has no effect.
- Across processes, only broadcasts travel; the socket helpers (`fetchSockets`, `socketsJoin`...) act on the current process.
- With several processes on one port, clients must use `transports: ['websocket']`.

[Unreleased]: https://github.com/marcosmarcolin/socket.io-server/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/marcosmarcolin/socket.io-server/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marcosmarcolin/socket.io-server/releases/tag/v0.1.0
