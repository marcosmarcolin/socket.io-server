# socket.io-server

A **Socket.IO v4** server for PHP, built on **Workerman 5**. Composer package `marcosmarcolin/socket.io-server`, namespace `Marcolin\SocketIO\`.

This file is the specification of how the package works. Behavior changes start here.

## Scope

**It is:**
- A server for `socket.io-client` v3 and v4: **Engine.IO protocol v4** and **Socket.IO protocol v5**.
- Transports: HTTP long-polling and WebSocket, with the polling-to-WebSocket upgrade.
- PHP >= 8.1, Workerman ^5.2, standalone. No framework dependency.

**It is not** (out of scope; don't implement without an explicit decision):
- A server for `socket.io-client` 1.x/2.x (Engine.IO v3). Those clients have [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).
- WebTransport, JSONP, custom parsers (msgpack), dynamic namespaces (regex or callback), connection state recovery.
- Compatibility with the Node adapters (`@socket.io/redis-adapter`, `@socket.io/redis-emitter`): `RedisAdapter` speaks only to other servers of this package.

## Sources of truth

When in doubt about behavior, these win, in this order:
1. The official specs in [socketio/socket.io](https://github.com/socketio/socket.io):
   - `docs/engine.io-protocol/v4-current.md`
   - `docs/socket.io-protocol/v5-current.md`
2. The official compliance suites, in the same directories:
   - `docs/engine.io-protocol/v4-test-suite/`
   - `docs/socket.io-protocol/v5-test-suite/`
3. The TypeScript reference implementation: `packages/engine.io`, `packages/socket.io` and `packages/socket.io-parser`.
4. The documentation: https://socket.io/docs/v4/

Never implement from memory. When unsure, read the spec or the reference code.

## Definition of done

A change is done only when all of these hold:
1. **It is tested.** New behavior comes with tests, and a bug fix comes with a test that fails without the fix. No untested code is merged.
2. **The quality tools pass:** PHPUnit, phpcs (PSR-12) and PHPStan at level `max`, locally in Docker and in CI.
3. **The protocol suites pass in CI**, once they exist:
   - the official **Engine.IO v4 suite**, against a pure Engine.IO server on `/engine.io/` that echoes every message back (`tests/Compliance/engine-io-server.php`, run by `tests/Compliance/engine-io.sh`);
   - the official **Socket.IO v5 suite**, against the reference server described in the spec: `pingInterval` 300, `pingTimeout` 200, `connectTimeout` 1000, a `/custom` namespace, and the `auth`, `message-back` and `message-with-ack` handlers (`tests/Compliance/socket-io-server.php`, run by `tests/Compliance/socket-io.sh`).
4. **The official client works:** an integration test with `socket.io-client@4` (pinned version) covers polling, WebSocket, upgrade, namespaces, rooms, acks, binary and multi-process (`tests/Integration/`, run by `tests/Integration/run.sh`, `tests/Integration/run.sh multi-process` and `tests/Integration/run.sh multi-process-redis`).
5. **The docs are current:** both READMEs, the user guides in `docs/` (configuration, API reference, several processes, migration, development), CLAUDE.md and examples match the behavior, and `CHANGELOG.md` lists every user-visible change under `[Unreleased]`. Each example has a test with the official client (`tests/Integration/client/examples/`) that CI runs, so an example that stops working fails the build.

## Code rules

- Follow good development practices: small focused classes, single responsibility, dependency injection, no global state, no hidden side effects, clear names.
- **Everything is typed:**
  - `declare(strict_types=1);` in every file;
  - typed parameters, returns and properties, with `readonly` wherever possible;
  - enums for packet types and states;
  - generics and array shapes in PHPDoc where PHP's types can't express them.
- **Everything is testable:**
  - dependencies are injected, never constructed deep inside;
  - time goes through the `Scheduler` interface, never `Workerman\Timer` directly, and tests use a fake scheduler;
  - network I/O stays behind small interfaces that tests can replace with fakes.
- Classes are `final` by default. Inheritance only where there's a documented extension point, such as adapters.
- **No dynamic properties**, neither on our classes nor on Workerman objects. Per-connection state goes in a `WeakMap` or in `$connection->context`.
- Magic only where the public API requires it and it's documented: `__get` for `broadcast`, `volatile` and `local` (and `volatile` on the `Emitter`), for the read-only `connected`, `disconnected` and `rooms` of `Socket`, and for `$io->worker`, annotated with `@property-read`.
- PHPStan at level `max`. No new `ignoreErrors` without a written justification. Third-party docblocks PHPStan can't read are corrected with stubs in `phpstan-stubs/`, each explaining why.
- Short comments, one line, only to explain why.
- No `echo`/`print`. Errors become exceptions or `error` events.
- No production dependencies besides Workerman. `workerman/channel` is optional (in `suggest`).
- Everything in English: code, comments, docs, commits, PRs. The exceptions are the Brazilian Portuguese translations of the user documentation: `README.pt-BR.md` and `docs/*.pt-BR.md`.
- **`CHANGELOG.md`** follows Keep a Changelog, in English only, like the commits it summarizes. A PR that changes behavior, the API or the requirements adds its line under `[Unreleased]`. A release renames that section to the version and date.
- **The README stays short:** what the package is, features, requirements, installation, a quick start, the examples and links to `docs/`. Details go in the guides in `docs/`.
- **User docs in two languages, always in sync.** `README.md` and `docs/*.md` (English) are the reference. Every change to one goes to its `.pt-BR.md` translation in the same PR, and neither has content the other lacks. Code blocks are identical in both, except the comments of shell commands.

## Verification

Never run PHP, Composer, PHPUnit, phpcs or PHPStan on the host. Always use Docker:

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint
tests/Compliance/engine-io.sh                  # official Engine.IO v4 suite, in Docker
tests/Compliance/socket-io.sh                  # official Socket.IO v5 suite, in Docker
tests/Integration/run.sh                       # the official socket.io-client against our server, in Docker
tests/Integration/run.sh multi-process         # the same over two processes and a channel server
tests/Integration/run.sh multi-process-redis   # the same two processes, joined by a Redis server
tests/Integration/run.sh examples              # every example in examples/, each against its test
```

The integration client (`tests/Integration/client/`) pins `socket.io-client` in `package.json`, and its `package-lock.json` is committed, so `npm ci` checks every package's integrity hash. Its tests use `node:test`, so the client is the only dependency. Each scenario runs over polling only, WebSocket only, and polling with the upgrade. To move to a newer client, regenerate the lockfile in Docker with `npm install --package-lock-only --ignore-scripts`, then read what changed.

Both scripts share `tests/Compliance/suite.sh`, which downloads the suite at a pinned commit of `socketio/socket.io` into `.cache/`, checks each file against a SHA-256 checksum, and runs it with a pinned Node image that shares the server container's network. To move to a newer suite, update the commit and the checksums together, after reading what changed.

## Examples

`examples/<name>/` holds a `start.php` (Socket.IO on port 3000, its page on port 3001 through a plain Workerman HTTP worker) and an `index.html` that loads `socket.io-client` 4.8.4 from `cdn.socket.io` with its SRI hash. Examples are code: phpcs and PHPStan check them, they follow the code rules, and `tests/Integration/client/examples/<name>.test.js` checks each one. Run one with `tests/Integration/run.sh example <name>`; `run.sh` starts a Redis next to the `redis` and `emitter` examples.

Examples show the API users will write, so they use Workerman directly where users would, such as `Workerman\Timer::add()` in `workerStart` for periodic work (the `dashboard` example). The `Scheduler` rule applies to `src/`, whose timing tests replace.

## Documentation

User docs live in the README and in `docs/`, each in English (the reference) and Brazilian Portuguese (`.pt-BR.md`), updated together in the same PR as the change they describe:

| File | Covers | Update it when |
|---|---|---|
| `README.md` | what the package is, features, requirements, installation, a short quick start, the examples table, links to `docs/` | a feature, requirement or example is added or removed; keep it short, details go to `docs/` |
| `docs/configuration.md` | server options, CORS and cookies, `allowRequest`, TLS | an option or its behavior changes |
| `docs/api.md` | `SocketIO`, `Nsp`, `Socket`, events and disconnect reasons, acknowledgements, broadcasting, errors, JSON and binary data | the public API or its behavior changes |
| `docs/reverse-proxy.md` | nginx and HAProxy in front of the server: TLS, the upgrade headers, the read timeout, client addresses, and sticky sessions with one port per process | the `cookie` option, transports or anything a proxy must know changes; its configs were checked with nginx 1.27 and HAProxy 3.0 in Docker, and are checked again when they change |
| `docs/multiple-processes.md` | `ChannelAdapter`, `RedisAdapter`, what crosses processes and the limits | an adapter or cross-process behavior changes |
| `docs/migrating-from-phpsocket.io.md` | moving an app and its clients from phpsocket.io | a change affects how phpsocket.io code maps to ours |
| `docs/development.md` | the Docker commands, the test suites, secret scanning | a test command, suite or tool changes |
| `CHANGELOG.md` | every user-visible change, English only | always, under `[Unreleased]` |

Docs link to each other with relative paths and anchors; check them after moving or renaming a heading. A new guide goes in this table and in the README's Documentation list.

## Workflow

- **Secrets never get committed.** After cloning, run `git config core.hooksPath .githooks`: the pre-commit hook runs gitleaks in Docker on the staged changes, and it blocks the commit if Docker isn't running. The CI `secrets` job scans the whole history. Both go through `.githooks/gitleaks`, which pins the image version and fails when gitleaks logs an error, because gitleaks exits 0 when git fails mid-scan.

- **Every commit follows [Conventional Commits](https://www.conventionalcommits.org/):** `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`, and `!` or a `BREAKING CHANGE:` footer for breaking changes.
- Commits are signed (GPG) and authored as `marcolindev@gmail.com`; this is configured in the repo's local git config.
- **After the first commit, every change goes through a pull request.** No direct pushes to `main`. One focused change per PR, with green CI, tests and a description of what changed and how it was verified.
- **Merges, tags, releases and anything published need Marcos's explicit approval each time.** A green CI is not approval.
- The repo is `marcosmarcolin/socket.io-server`, public since v0.1.0 and published on Packagist, which GitHub updates on each tag.
- **Releases** follow SemVer, 0.x until the API is settled: a PR renames `[Unreleased]` in `CHANGELOG.md` to the version and date, then, with Marcos's approval, the merge commit gets a signed tag `vX.Y.Z` and a GitHub Release with that changelog section.

## Architecture

Two independent layers, as in the Node reference implementation. The Engine layer works on its own.

```
src/
  Binary.php                    binary value (attachments and binary frames)
  Event/Emitter.php             on/once/off/listeners plus a protected dispatch
  Event/AnyListeners.php        the catch-all listeners of onAny() and onAnyOutgoing()
  Engine/                       Engine.IO v4
    Server.php                  validates requests, handshake, sessions, routing; no Workerman code
    ServerError.php             HTTP error codes and messages
    Rejection.php               a refused request: its ServerError and the message sent, which allowRequest may set
    Cors.php, CorsHttpExchange.php  CORS headers, added to every polling answer
    ResponseHeaders.php, HeadersHttpExchange.php  the `initial_headers`/`headers` events on a session's answers
    Request.php                 an HTTP request: the session's handshake, or the one opening a WebSocket
    SessionIdGenerator.php, RandomSessionIdGenerator.php  20-character base64url session ids
    Socket.php                  session: state, heartbeat, upgrade, write buffer
    SocketState.php, CloseReason.php  session state; close reasons with the reference's strings
    ServerOptions.php           pingInterval, pingTimeout, maxPayload, upgradeTimeout, path, cors, transports, allowRequest, allowUpgrades, cookie; fromArray()
    Cookie.php                  the cookie option: io=<sid> on the polling handshake, for sticky sessions
    Transport/Transport.php     base transport (Emitter): packet, ready, drain, error, close
    Transport/Polling.php       pending GET, POST, noop on upgrade
    Transport/WebSocket.php     one packet per frame, text or binary
    Transport/HttpExchange.php, Transport/WebSocketConnection.php  I/O interfaces, faked in tests
    Protocol/HttpOrWebSocket.php  ProtocolInterface: Workerman HTTP, or switch to WebSocket
    Protocol/WebSocket.php      extends Workerman\Protocols\Websocket, exposes the opcode
    Workerman/                  Workerman implementations of Scheduler, HttpExchange and WebSocketConnection,
                                and the wiring of a Worker's callbacks to Server
    Parser.php, Packet.php, PacketType.php, InvalidPacketException.php
    Scheduler.php
  SocketIO.php                  Socket.IO server (public API)
  Nsp.php                       namespace: middlewares, connected sockets
  Socket.php                    user-facing socket
  SocketEmitter.php             timeout()/volatile/compress() on a socket: immutable, emits only
  Client.php                    one Engine.IO connection and its per-namespace sockets
  DisconnectReason.php, ConnectionError.php, TimeoutException.php
  SocketIdGenerator.php, RandomSocketIdGenerator.php
  BroadcastOperator.php         to/in/except/compress/volatile/timeout/emit and the socket helpers, immutable
  RemoteSocket.php              a socket on another server, from fetchSockets() with a callback
  AckCollector.php              the answers to one broadcast with acknowledgement, until all arrive or the timeout
  Emitter.php                   sends to the servers' clients from outside them (PHP-FPM), through a Publisher
  Parser/                       socket.io-parser (protocol v5)
    Encoder.php                 Packet → list<string|Binary>: the text part, then one Binary per attachment
    Decoder.php                 one per connection: add(string|Binary): ?Packet, reset()
    Packet.php, PacketType.php, InvalidPacketException.php
  Adapter/
    Adapter.php                 the extension point for other processes
    BroadcastOptions.php        rooms, except and volatile of a broadcast
    InMemoryAdapter.php         local rooms and broadcast; not final, the base of other adapters
    PubSubAdapter.php           the cluster protocol through a PubSub; abstract, the base of the two below
    Cluster/                    MessageType (the reference's numbers), Nodes (server count), Request (waiting for responses),
                                Channels and Wire (channel names and message shapes, shared with the Emitter)
    Publisher.php               publish only: what the Emitter needs; PubSub extends it
    ChannelAdapter.php          PubSubAdapter on workerman/channel; final, only adds factory()
    RedisAdapter.php            PubSubAdapter on Redis; final, only adds factory()
    PubSub.php                  publish/subscribe between processes, faked in tests
    WorkermanChannelPubSub.php  PubSub on workerman/channel: the only class touching the static Channel\Client
    Redis/                      our own Redis pub/sub client, on Workerman only
      RedisPubSub.php           PubSub over two connections, a publisher and a subscriber
      RedisConnection.php       one connection: login, queue until ready, reconnect
      Resp.php, RedisError.php  RESP2 commands out, replies in
      RedisUrl.php              redis://[[user]:password@]host[:port][/database]
      Dialer.php, Link.php      TCP I/O interfaces, faked in tests
      WorkermanDialer.php, WorkermanLink.php  the same on AsyncTcpConnection
      BlockingRedisPublisher.php  a Publisher with blocking I/O, for the Emitter outside the event loop
```

### Workerman 5 integration

- **Entry point.** `new SocketIO(3000, $options)` creates a worker on `tcp://0.0.0.0:3000`. To pick the address, the worker count or TLS (`$worker->transport = 'ssl'` plus the context), create the worker yourself and call `$io->attach($worker)`. Either way, call it before `Worker::runAll()`.
  - `attach()` takes a `tcp` or `ssl` worker, once. It keeps the worker's own `onWorkerStart`/`onWorkerStop` and emits `workerStart`/`workerStop` after them.
  - `$io->worker` is the attached worker, or `null`.

- **One port for HTTP and WebSocket.** `Protocol\HttpOrWebSocket` inspects the headers of each request on the connection, so a keep-alive connection can upgrade later:
  - a `GET` with `Upgrade: websocket` switches `$connection->protocol` to `Protocol\WebSocket` and delegates to it (`TcpConnection` re-reads `protocol` for every package);
  - anything else, including incomplete headers, goes to `Workerman\Protocols\Http`.
- **Text or binary frames.** Workerman's `Websocket` doesn't tell `onMessage` whether a frame was text or binary. `Protocol\WebSocket` overrides `decode()`, which receives the raw frame, and records the opcode in a static `WeakMap`. Sending binary uses Workerman's own `websocketType` mechanism, inside that class only.
- **Worker callbacks.** `Engine\Workerman\ServerBinding::bind($worker)` takes over the worker's protocol and callbacks, on a `tcp://` worker, before `Worker::runAll()`:
  - `onConnect` caps `maxPackageSize` at `maxPayload` plus Workerman's 16 KB header limit;
  - `onMessage` turns HTTP requests into a `WorkermanHttpExchange` for `Server::handleRequest()`, and hands WebSocket frames to the connection's `WorkermanWebSocketConnection`;
  - `onWebSocketConnect` runs `Server::verifyWebSocket()` before the 101 and answers an invalid request with a plain HTTP 400 and the error message, as the reference does;
  - `onWebSocketConnected` (Workerman 5.2) runs after the 101, sets `maxPackageSize` to `maxPayload` plus the frame header, and calls `Server::handleWebSocket()`, so the `open` packet goes out at once;
  - `onClose` reports the close to the WebSocket, or aborts an unanswered HTTP request.
- **Per-connection state** lives in `WeakMap`s keyed by `TcpConnection`. A `WorkermanHttpExchange` copies its request up front, because Workerman recycles `Request` objects and a poll is answered later.
- **Exceptions never leave a callback.** `ServerBinding` closes the connection (after a 500 if the request had no answer yet) and passes the exception to its error handler, which defaults to `Worker::log`.
- **Closing a WebSocket** sends a close frame with code 1000, then closes the TCP connection.
- **Workerman 5 pitfalls:**
  - An exception inside `onWebSocketConnect` or `onWebSocketConnected` calls `Worker::stopAll(250)` and **kills the worker**. Never throw there: reject by closing the connection.
  - The extra headers of a 101 go in `$connection->headers` inside `onWebSocketConnect`, as `"Name: value"` lines: `Websocket::dealHandshake()` writes them right after the callback. Workerman marks the property internal for HTTP, where `Http` consumes and clears it on each answer; on a connection that just upgraded, nothing reads it again.
  - Workerman calls `onWebSocketConnected` even when `onWebSocketConnect` already closed the connection, if the refusal is still in the send buffer (status `CLOSING`). `ServerBinding` skips a connection that is no longer established.
  - Workerman caches decoded packages by their raw bytes and may deliver a cached object without decoding again. Never keep state that depends on `decode()` running for every package, except the opcode, which is part of those bytes.
  - Workerman's files use `strict_types`, so `input()` must return an `int`.
  - Exceptions in `onMessage` are caught by Workerman and don't kill the worker. Handle them anyway.

## Protocol behavior

### Engine.IO

- **Default path:** `/socket.io/`, normalized with a trailing slash; `/socket.io` matches too. Other paths get 404 (the reference's standalone server answers 501). Required query: `EIO=4`, `transport=polling|websocket`, and `sid` after the handshake.
- **HTTP errors** return 400 (403 for code 4, as the reference does) with `Content-Type: application/json` and a body `{"code":N,"message":"..."}`, using Node's codes:

  | Code | Message |
  |---|---|
  | 0 | Transport unknown |
  | 1 | Session ID unknown |
  | 2 | Bad handshake method |
  | 3 | Bad request |
  | 4 | Forbidden |
  | 5 | Unsupported protocol version |

- **Request validation**, in the reference's order:
  - a missing or unknown `transport`, or one the `transports` option leaves out, is code 0 (as in the reference);
  - an `Origin` header with invalid characters is code 3;
  - with a `sid`: an unknown session is code 1; over HTTP, a `transport` other than the session's current one is code 3 (this rejects HTTP after the upgrade); `EIO` other than `4` is code 3;
  - without a `sid`: a method other than `GET` is code 2; `transport=websocket` over HTTP, or `transport=polling` on a WebSocket, is code 3; then the `allowRequest` option decides, if set; then `EIO` other than `4`, or missing, is code 5.

  A WebSocket request is validated before the handshake is answered (`Server::verifyWebSocket()`) and again when the connection is handed over, in case the session closed in between. `allowRequest` is asked only the first time.
- **`allowRequest`:** a `Closure(Engine\Request): bool|string`, called synchronously for each handshake (requests with a `sid` skip it). `true` accepts; `false`, or any other value, refuses with code 4 and HTTP 403. A non-empty string replaces the message, as the reference's `errorContext.message` does; over WebSocket, the message goes in the plain HTTP 400. Exceptions propagate to `ServerBinding`, which closes the connection (with a 500 over polling).
- **Handshake:** only via `GET` without `sid` (or WebSocket without `sid`). It answers an `open` packet with `sid`, `upgrades`, `pingInterval`, `pingTimeout` and `maxPayload`. `upgrades` is `["websocket"]` on polling and `[]` on WebSocket, keeping only the transports the `transports` option allows (`[]` on polling when it is `['polling']`), and `[]` with `allowUpgrades` set to `false`. As in the reference, that option only changes the offer: a client that tries the upgrade anyway still gets it. The session id is 15 random bytes in base64url (20 characters), like the reference's `base64id`. A closed session leaves the registry at once, so later requests with its `sid` get code 1.
- **`maxPayload`:** a polling `POST` body larger than it gets 413 and the session goes on, as in the reference.
- **Heartbeat:** the **server** sends `ping` every `pingInterval`. Without a `pong` within `pingTimeout`, the session closes with `ping timeout`.
  - the next `ping` is scheduled `pingInterval` after the `pong`;
  - any other packet received while waiting for the `pong` restarts the `pingTimeout` once per ping, as the reference does;
  - a `ping` from the client closes the session with `transport error` (v4 heartbeats go from server to client only).
- **Close reasons** (`CloseReason`): `ping timeout`, `transport close`, `transport error`, `parse error` (malformed packet) and `forced close` (closed by the server). A `close` packet over WebSocket closes the session with `transport close`.
- **Polling:**
  - a `GET` waits until there are packets to send; a `POST` answers `ok`;
  - a second concurrent `GET` gets 400, the pending one gets `1` (close), and the session closes;
  - a pending `GET` cancelled by the client (TCP connection closed) closes the session;
  - a received `close` packet answers the pending `GET` with `6` (noop), and the session closes;
  - an invalid payload gets 400 and closes the session;
  - a `POST` with `Content-Type: application/octet-stream` gets 400 and closes the session (v4 sends binary as base64 text);
  - any other method on an existing session gets 400;
  - a close requested while no `GET` is pending is sent with the next `GET`, or completes after 30 s without one.

  Workerman delivers a request only once its body is complete, so each `POST` is handled whole. The spec's rule against overlapping `POST`s therefore needs no code.
- **Upgrade:**
  1. a WebSocket arrives with the `sid` of a polling session;
  2. the client sends `2probe` and the server answers `3probe`;
  3. the pending `GET`, or the next one, gets `6`. This is event-driven: the reference retries every 100 ms instead;
  4. on `5`, the WebSocket becomes the active transport and the buffer is flushed through it.

  After the upgrade, HTTP requests or another WebSocket with the same `sid` are rejected. A second WebSocket during an upgrade is closed too. If the upgrade doesn't complete within `upgradeTimeout` (10 s), or any packet other than `2probe` or `5` arrives on the new transport, the WebSocket is closed and the session stays on polling.
- **Polling payload:** packets separated by `\x1e`; binary as `b` + base64. Over WebSocket, binary goes as a raw binary frame.
- **CORS:** the `cors` option, as the `cors` package the reference uses, including the `OPTIONS` preflight.
  - `origin` is `'*'` (default), a list of origins, a `Closure(string $origin, Request $request): bool` deciding per request, or `null` to disable CORS. `credentials` (default `false`) adds `Access-Control-Allow-Credentials: true`, which browsers need to send cookies cross-origin.
  - The headers go on every polling answer, errors included.
  - With a list or a `Closure`, an allowed origin is echoed with `Vary: Origin`, and any other origin gets no `Access-Control-Allow-Origin`, so the browser blocks it. The `Closure` runs only when the request has an `Origin`, and only `true` allows.
  - `'*'` with `credentials` throws: browsers reject that pair, and the `cors` package would send it anyway.
  - The preflight answers 204 with `GET, POST, OPTIONS` and echoes `Access-Control-Request-Headers`. With CORS disabled, `OPTIONS` is an ordinary request and gets code 2.
  - CORS doesn't cover WebSocket, in browsers or in the reference. `docs/configuration.md` shows the middleware that checks `Origin` on every transport.
- **`cookie` option**, as the reference's: `false` by default; `true` or an array of attributes (`name` `io`, `path` `/`, `domain`, `httpOnly` `true`, `secure` `false`, `sameSite` `Lax`, `maxAge`) makes the polling handshake answer set `<name>=<sid>`, serialized in the order of the reference's `cookie` package, before `initial_headers` runs, so its listeners see it. Later answers and the WebSocket 101 get none, as in the reference. Values that would break the header throw.
- **Engine events** on `Engine\Server` (`$io->engine`), as the reference's:
  - `initial_headers` and `headers` get a mutable `ResponseHeaders` and the `Request`. `initial_headers` runs for a request without `sid` (the polling handshake, or a WebSocket that opens a session), then `headers` runs for it too. Over polling, `HeadersHttpExchange` runs them for each `200` answer of a session (the data and `ok` answers), not for errors or the CORS preflight. Over WebSocket, `ServerBinding` asks `Server::webSocketHeaders()` once `verifyWebSocket()` accepted, and writes the result into the 101.
  - `ResponseHeaders` validates names (header tokens) and values (no CR, LF or NUL); `append()` keeps one line per value, for several `Set-Cookie`. `HttpExchange::respond()` takes `string|list<string>` values.
  - `connection_error` gets `(Request, int $code, string $message)` for every request `verify()` refuses, over HTTP (`reject()`) or before a 101 (`verifyWebSocket()`), with the message `allowRequest` returned. The reference passes one object with a `context` we don't build.

### Socket.IO

- **No implicit connection:** the client must send `CONNECT` for each namespace, including `/`. Without a `CONNECT` within `connectTimeout` (45 s), the connection closes.
- **Answering `CONNECT`:** success answers `CONNECT` with `{"sid":"<id>"}`. An unknown namespace answers `CONNECT_ERROR` with `{"message":"Invalid namespace"}`, and a middleware error answers `CONNECT_ERROR` with `{"message":...,"data":...}`.
- **`CONNECT` payload:** becomes `$socket->handshake['auth']`.
- **Handshake:** `$socket->handshake` has the reference's keys: `headers` (lower-case names), `time`, `address`, `xdomain`, `secure`, `issued` (ms), `url`, `query` and `auth`. `$socket->request` is the `Engine\Request` that opened the Engine.IO session.
- **Middlewares** (`$io->use()`, `$nsp->use()`) run in order before `connection`. Each lets the socket in with `$next()` or refuses it with `$next($error)`, and may call `$next` later. Only the first call counts.
  - A `ConnectionError` sends its `data` too.
  - An exception thrown before `$next` refuses the socket; one thrown after it (from a `connection` listener, say) propagates.
  - If the connection closed while a middleware worked, the socket is dropped.
- **Dispatch is synchronous.** The reference defers some steps with `process.nextTick`; here, packets and middleware results are handled at once. An exception from a user listener propagates up to `ServerBinding`, which closes the connection and reports it.
- **One socket per namespace and connection:** a second `CONNECT` to a joined namespace, or a packet for a namespace the connection didn't join, closes the connection. After leaving a namespace (either side), the client can connect to it again with a new id.
- **Flags without hidden state:** `timeout()`, `volatile` and `compress()` on a `Socket` return an immutable `SocketEmitter`; the socket itself keeps no flags. `compress()` exists for API compatibility and has no effect: the server doesn't compress. `volatile` drops the emit when the transport can't take it right now.
- **Disconnect reasons** (`DisconnectReason`, given to listeners as strings): the Engine.IO close reasons, plus `server namespace disconnect`, `client namespace disconnect`, `forced server close` and `server shutting down`. `disconnecting` fires while the socket still has its rooms, `disconnect` after it left them.
- **Ids:** a socket id differs from its Engine.IO session id and differs per namespace. Format: 24 lowercase hex characters, which `PubSubAdapter` relies on to recognize sid rooms.
- **Strict parser validation** (any violation throws `Parser\InvalidPacketException`, which closes the connection), with the reference's error messages:
  - the type must be 0 to 6;
  - binary types require `N-`, with `N` from 1 to `maxAttachments` (10 by default, as in the reference);
  - the namespace runs until `,`;
  - an ack id is digits only (leading zeros allowed) and must fit in an `int`;
  - a `CONNECT` payload is optional and must be an object;
  - an `EVENT` must be a non-empty array whose name is a number, or a string that is not a reserved name;
  - an `ACK` must be an array;
  - a `CONNECT_ERROR` payload must be a string or an object;
  - a `DISCONNECT` has no payload;
  - the `EVENT` and `ACK` payloads are mandatory, as the spec says (the reference accepts them missing);
  - a placeholder must point at a received attachment, and text can't arrive while attachments are expected, nor an attachment without a pending binary packet.
- **Binary:**
  - incoming attachments arrive as `Binary` and placeholders are rebuilt;
  - outgoing, any `Binary` in the data, at any depth, turns an `EVENT`/`ACK` into `BINARY_EVENT`/`BINARY_ACK` with placeholders. `JsonSerializable` objects are expanded with `jsonSerialize()`, like `toJSON()` in the reference, and other objects by their public properties;
  - `Binary` in any other packet type throws.
- **Encoder validation:** the encoder throws `InvalidArgumentException` for a packet that can't be sent: a namespace that doesn't start with `/` or contains `,`, a negative ack id, data of the wrong shape for the type, a `BINARY_*` type given directly (the encoder picks it), data nested more than 512 levels or circular, or data JSON can't encode.
- **JSON:** incoming data is decoded to PHP arrays. PHP can't tell an empty `{}` from `[]`, so send an empty object with `new stdClass()` or `(object) []`. The decoder tells objects from arrays by the payload's first character. The encoder matches `JSON.stringify`: slashes, Unicode and line terminators are not escaped.
- **Acks:** if the last argument of `emit` is a `Closure`, it becomes the ack callback. Received events with an id get an ack `Closure` as their last argument, which answers only once.
  - Ack ids are counted per namespace, as in the reference.
  - With `timeout()`, the callback gets `null` and the answer, or a `TimeoutException` ("operation has timed out"). In that case, its other required parameters get `null`, so `function (?Throwable $err, $answer)` works.
  - **Broadcast acks**, as the reference's `BroadcastOperator::emit()`:
    - a broadcast takes a callback only after `timeout()`; without it, it throws (the reference fires at once);
    - one ack id from the namespace's counter serves every socket reached in the process;
    - each server's `broadcastWithAck()` reports how many sockets it reached, and each answer counts once, with its first argument; `AckCollector` waits for `serverCount()` reports;
    - the callback gets `null` and the answers in arrival order once every server reported and all answered (at once with `[]` when none was reached), or a `TimeoutException` and the answers so far, after which every socket of the namespace forgets the id;
    - a `volatile` packet dropped for a busy transport still counts, so the wait ends with the timeout.
- **Socket middlewares** (`$socket->use()`), as the reference's `socket.use()`:
  - they run in order for each received event of a connected socket, after the `onAny` listeners and before the event's own, getting `[name, ...args]` with the ack `Closure` last when asked;
  - `$next()` lets the event through; `$next($error)` stops it and dispatches `error` with `$error`, and the socket stays connected; only the first call counts, and it may come later;
  - an exception thrown before `$next` stops the event the same way; one thrown after it (from a listener) propagates, as with namespace middlewares;
  - the list is copied when the event arrives, so a middleware added meanwhile applies from the next event; an event let through after the socket left is dropped;
  - a middleware taking `array &$event` by reference changes what the next ones and the listeners get (JavaScript middlewares mutate the array the same way).
- **Catch-all listeners**, as in the reference:
  - `onAny`/`prependAny`/`offAny`/`listenersAny` get each received event of a connected socket as `(string $event, ...$args)`, with the ack `Closure` last, before the event's own listeners; those are skipped if an `onAny` listener disconnected the socket. Numeric event names arrive as strings, as with `on()`.
  - `onAnyOutgoing` and its family get each event sent to the socket, without the ack callback, before it is written: from `emit()`/`send()`, and from the adapter for every socket a broadcast reaches (with or without ack).
  - The adapter asks for the event's data only when a reached socket has an outgoing listener, once per broadcast. `PubSubAdapter` decodes another process's parts for it then; parts that aren't one event notify nobody.
  - Listeners added or removed while listeners run take effect from the next event.
- **Reserved names** (`Packet::RESERVED_EVENTS`): `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` and `removeListener` can't be emitted to the client; doing so throws. A received event with one of these names is invalid, as in the reference.

## Public API (target)

It keeps phpsocket.io's shape, to ease migration, plus the v4 features.

```php
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

$io = new SocketIO(3000, [
    'pingInterval' => 25000,
    'pingTimeout' => 20000,
    'maxPayload' => 1_000_000,
    'connectTimeout' => 45000,
    'path' => '/socket.io/',
    'cors' => ['origin' => '*'],
]);

$io->use(function (Socket $socket, Closure $next): void {   // namespace middleware
    $next($socket->handshake['auth']['token'] ?? null ? null : new Exception('unauthorized'));
});

$io->on('connection', function (Socket $socket) use ($io): void {
    $socket->data['user'] = 'ana';                 // free-form user data
    $socket->join('room1');
    $socket->emit('hello', 'world');
    $socket->emit('ask', 1, function ($answer): void {});     // ack
    $socket->timeout(5000)->emit('ask', function (?Throwable $err, $answer): void {});
    $socket->broadcast->emit('joined', $socket->id);
    $socket->to('room1')->except('room2')->emit('msg', 'hi');
    $socket->on('chat', function (string $msg, ?Closure $ack = null): void {});
    $socket->on('disconnect', function (string $reason): void {});
    $io->to('room1')->emit('news', ['a' => 1]);
    $io->of('/admin')->emit('alert');
});

Worker::runAll();
```

- `SocketIO`: `$io->engine` (its `initial_headers`, `headers` and `connection_error` events), `of()`, `on()` (`workerStart`/`workerStop` are its own; any other event goes to the `/` namespace, as the reference forwards every emitter method), `serverSideEmit()`, `fetchSockets(?Closure)`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()` (all on `/`), `use()`, `to/in/except`, `emit`, `send`, `compress`, `volatile`, `local`, `timeout`, `adapter()`, `attach(Worker)`, `close()`, `$io->worker`, `$io->sockets` (the `/` namespace).
- **`close()`**, as the reference's:
  1. every connected socket of every namespace leaves with `server shutting down`, with no `DISCONNECT` sent;
  2. each adapter's `close()` runs (`PubSubAdapter` unsubscribes from its channels);
  3. `Engine\Server::close()` closes every session at once (`Engine\Socket::close(true)`, dropping what is buffered; a pending poll gets `1`);
  4. the attached worker stops accepting connections in this process (`unlisten()`, as `httpServer.close()`).

  Sockets still in their middlewares never connect. It is not called on `workerStop`: the reference has no such hook, and Workerman closes the connections itself.
- `Nsp`: `on('connection')` and the `serverSideEmit()` events, `use()`, `to/in/except`, `volatile`, `local`, `timeout`, `emit`, `serverSideEmit()`, `fetchSockets(?Closure)`, `allSockets()`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()`.
- `Socket`: `id`, `nsp`, `client`, `conn`, `request`, `handshake`, `data`, `rooms`, `connected`, `emit`, `send`, `on`, `onAny`, `prependAny`, `offAny`, `listenersAny`, `onAnyOutgoing`, `prependAnyOutgoing`, `offAnyOutgoing`, `listenersAnyOutgoing`, `use`, `join`, `leave`, `to/in/except`, `broadcast`, `local`, `volatile`, `compress()`, `timeout()`, `disconnect(bool $close = false)`.
- Broadcasts, `socketsJoin`, `socketsLeave`, `disconnectSockets` and `fetchSockets` with a callback go through the adapter, so they reach every process with `ChannelAdapter` or `RedisAdapter`; `allSockets` and `fetchSockets` without a callback read the local process.
- `RemoteSocket`: `id`, `handshake`, `rooms`, `data`, `emit`, `join`, `leave`, `disconnect`.
- **Broadcast** (`InMemoryAdapter`), as in the reference:
  - `to()` targets the sockets in any of the rooms, each once; with no room, every socket of the namespace;
  - `except()` leaves out the sockets in any of its rooms;
  - `$socket->broadcast`, `$socket->to()` and `$socket->except()` leave the socket itself out;
  - only connected sockets are reached, not those still in their middlewares;
  - the packet is encoded once for all sockets;
  - `allSockets()` ignores `except()`, as in the reference, and `fetchSockets()` applies it;
  - `volatile` skips sockets whose transport is busy.

## Multiple processes

- `InMemoryAdapter` is the default.
- `PubSubAdapter` holds the cross-process logic, on any `PubSub`; `ChannelAdapter` (workerman/channel) and `RedisAdapter` (Redis) only add a `factory()`. It carries over phpsocket.io 3.x's `ChannelAdapter` fixes:
  - per-namespace channels (`<key>#<nsp>#` and `<key>#<nsp>#<room>#`, the key `socket.io` by default), with one listener per channel;
  - sid rooms are detected by their shape (24 hex characters) and go through the namespace's default channel instead of getting their own;
  - a channel is unsubscribed only once its room is empty in the process;
  - creating the adapter outside the event loop throws a clear error (the right place is `workerStart`).
- **How a broadcast crosses processes:**
  - `PubSubAdapter` delivers it to this process's sockets first, then publishes it already encoded, so no other process encodes it again;
  - each adapter has a random `uid` and ignores its own messages, since a publisher receives them too;
  - a message is an array of scalars, never objects: `uid`, `type` (`Cluster\MessageType`, numbered as the reference's) and the type's fields, such as `opts` (`rooms`, `except`, `volatile`) and `parts` (encoded packets as `['text'|'binary', string]`). `unserialize()` then creates no class of ours, and the broker needs none of our code. Malformed messages are ignored.
- **One publish per broadcast.** A single room goes to its room channel. Several rooms, a sid room, or no room go once to the namespace channel, and each process filters by the rooms itself. phpsocket.io publishes to each room's channel, so a process with members in two of the rooms delivers twice; this fixes it.
- **The cluster protocol**, as the reference's `ClusterAdapterWithHeartbeat` (`packages/socket.io-adapter/lib/cluster-adapter.ts`):
  - **Server count:** a new adapter publishes `InitialHeartbeat`, which the others answer with `Heartbeat`. Each adapter publishes `Heartbeat` after `HEARTBEAT_INTERVAL` (5 s) without publishing anything; every publish puts it off. A server unheard for `HEARTBEAT_TIMEOUT` (10 s) is gone, and so is one that sends `AdapterClose` (from `close()`). `Cluster\Nodes` keeps one timer per server; `serverCount()` is 1 plus them. Each namespace counts its own servers.
  - **Requests and responses:** requests go to the namespace channel; a server answers on the requester's response channel, `<key>-response#<nsp>#<uid>#`. `Cluster\Request` waits for one response from each server known when asking, completes when all answered or left, and gives up after `REQUEST_TIMEOUT` (5 s) with a `TimeoutException` ("timeout reached: missing N responses") and the responses so far. Late or unasked-for responses are ignored.
  - **`socketsJoin`, `socketsLeave`, `disconnectSockets`** publish `SocketsJoin`/`SocketsLeave`/`DisconnectSockets` with their options (to the room channel for a single room, as broadcasts), then apply locally; each other server applies them to its own sockets.
  - **`serverSideEmit([$event, ...$args, ?$ack])`** publishes the event encoded as an `EVENT` packet, so its data follows `emit()`'s rules. Receivers dispatch it on the namespace (`Nsp::receiveServerSideEmit()`), with an ack `Closure` last when asked; it answers once, with its first argument encoded as an `ACK` packet. Without other servers, the ack runs at once with `[]`. `connect`, `connection` and `new_namespace` are reserved, as in the reference: sending one throws, receiving one is ignored. `InMemoryAdapter::serverSideEmit()` throws (the reference only warns).
  - **`fetchSockets(Closure)`** (`Adapter::fetchClusterSockets()`): PHP has no promises, so `fetchSockets()` without arguments stays synchronous and local, and with a callback it asks every server. `FetchSockets` always goes to the namespace channel, never a room's, since every counted server must answer. Each server answers with `id`, `rooms`, and `handshake` and `data` as JSON strings (objects become arrays, what can't be encoded becomes null), so the message holds only scalars. The callback gets `(?TimeoutException, list<Socket|RemoteSocket>)`: this process's `Socket` objects first, then a `RemoteSocket` per socket elsewhere; on a timeout, the sockets so far (the reference rejects without them). A response with any malformed entry is ignored. `InMemoryAdapter` calls back at once with its sockets.
  - **`RemoteSocket`**: `id`, `handshake`, `rooms`, `data`, and `emit()`, `join()`, `leave()`, `disconnect()`, each through `$nsp->to($id)`/`in($id)`, so through the adapter. `timeout()` returns a copy whose `emit()` takes a callback getting `(?Throwable, mixed $answer)`, one answer instead of a list, as the reference's `expectSingleResponse`; a callback without `timeout()` throws.
  - **Broadcast acks:** `BroadcastOptions` carries the `timeout`. `PubSubAdapter::broadcastWithAck()` publishes the packet with a `requestId` to the namespace channel (never a room's: every counted server must report), then delivers locally. Each other server decodes it, sends it under an ack id of its own namespace, and answers on the requester's response channel with `BroadcastClientCount`, then one `BroadcastAck` per answer (its first argument, as an `ACK` packet). Both sides drop the request after the timeout: the requester forgets the `requestId`, the others make their sockets forget the ack id. A server that never reports (gone without `AdapterClose`) makes the wait end with the timeout.
  - **The `local` flag** (`$io->local`, `$nsp->local`, `$socket->local`, and `->local` on an operator), as the reference's: `BroadcastOptions::$local` makes `PubSubAdapter` publish nothing, so broadcasts, the socket helpers and `fetchSockets(Closure)` act on this process only. A local broadcast with an ack waits for this server's count only (the reference still waits for `serverCount()` reports and times out). `$socket->local` leaves the socket out, as `broadcast` does.
  - **Not yet:** `allSockets` reads the local process (it is deprecated in the reference).
- **`Emitter`**, as the reference's `@socket.io/redis-emitter` and `@socket.io/postgres-emitter`: sends from code that isn't a server.
  - It publishes through a `Publisher` exactly what `PubSubAdapter` would, with the uid `emitter` (`Emitter::UID`), using `Cluster\Channels` and `Cluster\Wire`, so the two can't drift. Servers never count that uid as a node, as `ClusterAdapterWithHeartbeat` skips `EMITTER_UID`.
  - API: `of()`, `to()`/`in()`, `except()`, `volatile` (each returns a copy), `emit()`, `send()`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()`, `serverSideEmit()`. A `Closure` argument throws (no acks, no `fetchSockets`), and so do reserved names.
  - `Emitter::redis($url, $key, $timeout)` builds it on `BlockingRedisPublisher`: `stream_socket_client`, `AUTH` first when the URL has a password, `serialize()` as `RedisPubSub` does. It connects lazily, keeps the connection, and reconnects once when a kept connection dropped; a fresh connection that fails, an error reply or a refused login throws `RuntimeException`, never with the password. Replies read ahead are queued for their commands.
  - The Channel adapter has no emitter: `Channel\Client` needs the event loop.
- **Setup:** in `workerStart`, call `$io->adapter(ChannelAdapter::factory($host, $port))` with a `Channel\Server` worker running, or `$io->adapter(RedisAdapter::factory($url, $key, $onError))`. `PubSub` is the seam that keeps the static `Channel\Client` and the Redis connections out of everything else. The `InMemoryAdapter` hooks `roomCreated()`/`roomDeleted()` drive the room subscriptions.
- **`RedisAdapter`** has its own client, on Workerman's `AsyncTcpConnection`, so it adds no dependency. `workerman/redis` doesn't fit: after its first `SUBSCRIBE` it sends no other command, so rooms couldn't subscribe or unsubscribe, and it prints errors with `echo`.
  - `factory()` arguments, all optional: `url` (`redis://127.0.0.1:6379`), `key` (`socket.io`; apps sharing one Redis need different keys), and `onError` (`Worker::log` by default). An invalid URL throws `InvalidArgumentException` before the event loop check; the message never includes the URL, which may hold a password.
  - URLs: `redis://[[user]:password@]host[:port][/database]`, percent-decoded. A user without a password, a path other than a database number, a query or another scheme (`rediss://` included: TLS isn't supported yet) throws. The database is ignored: pub/sub channels are shared by every database.
  - Two connections per process, shared by every namespace: a subscribed connection can't publish. Each speaks RESP2 and logs in with `AUTH` first when the URL has a password.
  - Messages are `serialize()`d, as workerman/channel does, and read back with `allowed_classes => false` and `max_depth`; a payload that isn't a serialized array is ignored, without warnings. Messages for a channel just unsubscribed are ignored.
  - **Reconnection:** a dropped or refused connection retries every second (`RedisConnection::RECONNECT_DELAY`, through the `Scheduler`). The subscriber subscribes every current channel again once ready. While not ready, a connection keeps up to 1,000 commands (`MAX_QUEUED`) and drops the rest; subscriptions aren't queued, since they are renewed on login. Publishes lost while disconnected are lost, as with any Redis pub/sub.
  - **Errors** go to `onError` as exceptions, never `echo`: a failure to connect once until the next login, a lost connection, a refused login, Redis error replies, a malformed reply (which also closes the connection), and exceptions thrown by listeners.
- With several processes on the same port, clients must use `transports: ['websocket']`, because polling breaks across processes without sticky sessions. `docs/multiple-processes.md` says so.
- **Multi-process tests** (`tests/Integration/multi-process-server.php`): two processes with `reusePort`, so the kernel spreads connections, plus a `Channel\Server`; or, with `ADAPTER=redis` (`run.sh multi-process-redis`), `RedisAdapter` on a pinned Redis image with a password, so the login is covered too. The `redis` example runs against the same Redis.
  - The tests first make sure clients landed on both processes, and that a broadcast crosses (retrying while the workers connect to the broker).
  - They cover emits, rooms, several rooms at once, `except`, `broadcast`, `socketsJoin`/`socketsLeave`, `disconnectSockets`, `serverSideEmit` with an ack, and `fetchSockets` with a callback plus `RemoteSocket::emit()`, broadcasts with an ack, a `RemoteSocket` ack, and, on Redis, the `Emitter` run from another PHP process (`tests/Integration/emitter.php`), across processes.
  - The **scale check** has 200 WebSocket clients get 20 broadcasts each: every client must get every message once and in order, and the time is printed. It catches loss and duplication, not performance.

## Roadmap

1. [x] Scaffold: Composer, Docker, PHPUnit, PHPStan max, phpcs, CI.
2. [x] Engine.IO parser (`Engine\Parser`).
3. [x] `Scheduler`, `Emitter`, `Engine\Socket` and transports, tested with a fake scheduler and fake connections.
4. [x] `Engine\Server`, the Workerman protocols, `WorkermanScheduler` and the Workerman implementations of `HttpExchange` and `WebSocketConnection`, with the official Engine.IO v4 suite running in CI.
5. [x] Socket.IO parser (`Encoder`, `Decoder`, `Binary`).
6. [x] `SocketIO`, `Nsp`, `Client`, `Socket`, `BroadcastOperator`, `InMemoryAdapter`, with the official Socket.IO v5 suite in CI.
7. [x] Integration with `socket.io-client@4` in CI (the multi-process scenario comes with step 8, since it needs `ChannelAdapter`).
8. [x] `ChannelAdapter`, with a multi-process integration test and a scale check.
9. [x] Complete README, examples, and a migration guide from phpsocket.io 3.x.
10. [x] First release, `v0.1.0`, on Packagist; the READMEs install with `composer require` and show its badges (version, downloads, PHP version).
11. [x] `RedisAdapter`, with its own Redis client, the multi-process tests on Redis and an example.
12. [x] The reference's cluster protocol on `PubSub`, for both adapters:
    - [x] heartbeats and server count, `socketsJoin`/`socketsLeave`, `disconnectSockets` and `serverSideEmit` across processes;
    - [x] `fetchSockets(Closure)` across processes, with `RemoteSocket`;
    - [x] broadcast acks across processes, and acks on `RemoteSocket`.
