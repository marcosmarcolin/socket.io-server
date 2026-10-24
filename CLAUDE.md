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
- WebTransport, JSONP, custom parsers (msgpack), dynamic namespaces (regex or callback), connection state recovery, a Redis adapter.

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
   - the official **Socket.IO v5 suite**, against the reference server described in the spec: `pingInterval` 300, `pingTimeout` 200, `connectTimeout` 1000, a `/custom` namespace, and the `auth`, `message-back` and `message-with-ack` handlers.
4. **The official client works:** an integration test with `socket.io-client@4` (pinned version) covers polling, WebSocket, upgrade, namespaces, rooms, acks, binary and multi-process.
5. **The docs are current:** both READMEs, CLAUDE.md and examples match the behavior.

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
- Magic only where the public API requires it and it's documented: `__get` for `broadcast` and `volatile`, and for the read-only `connected`, `disconnected` and `rooms` of `Socket`, annotated with `@property-read`.
- PHPStan at level `max`. No new `ignoreErrors` without a written justification.
- Short comments, one line, only to explain why.
- No `echo`/`print`. Errors become exceptions or `error` events.
- No production dependencies besides Workerman. `workerman/channel` is optional (in `suggest`).
- Everything in English: code, comments, docs, commits, PRs. The one exception is `README.pt-BR.md`, the Brazilian Portuguese translation of `README.md`.
- **Two READMEs, always in sync.** `README.md` (English) is the reference. Every change to it goes to `README.pt-BR.md` in the same PR, and neither one has content the other lacks.

## Verification

Never run PHP, Composer, PHPUnit, phpcs or PHPStan on the host. Always use Docker:

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint
tests/Compliance/engine-io.sh                  # official Engine.IO v4 suite, in Docker
```

The compliance script downloads the suite at a pinned commit of `socketio/socket.io` into `.cache/`, checks each file against a SHA-256 checksum, and runs it with a pinned Node image that shares the server container's network. To move to a newer suite, update the commit and the checksums together, after reading what changed.

## Workflow

- **Secrets never get committed.** After cloning, run `git config core.hooksPath .githooks`: the pre-commit hook runs gitleaks in Docker on the staged changes, and it blocks the commit if Docker isn't running. The CI `secrets` job scans the whole history. Both go through `.githooks/gitleaks`, which pins the image version and fails when gitleaks logs an error, because gitleaks exits 0 when git fails mid-scan.

- **Every commit follows [Conventional Commits](https://www.conventionalcommits.org/):** `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`, and `!` or a `BREAKING CHANGE:` footer for breaking changes.
- Commits are signed (GPG) and authored as `marcolindev@gmail.com`; this is configured in the repo's local git config.
- **After the first commit, every change goes through a pull request.** No direct pushes to `main`. One focused change per PR, with green CI, tests and a description of what changed and how it was verified.
- **Merges, tags, releases and anything published need Marcos's explicit approval each time.** A green CI is not approval.
- The repo is `marcosmarcolin/socket.io-server`, private until the first usable release.

## Architecture

Two independent layers, as in the Node reference implementation. The Engine layer works on its own.

```
src/
  Binary.php                    binary value (attachments and binary frames)
  Event/Emitter.php             on/once/off/listeners plus a protected dispatch
  Engine/                       Engine.IO v4
    Server.php                  validates requests, handshake, sessions, routing; no Workerman code
    ServerError.php             HTTP error codes and messages
    Cors.php, CorsHttpExchange.php  CORS headers, added to every polling answer
    Request.php                 an HTTP request: the session's handshake, or the one opening a WebSocket
    SessionIdGenerator.php, RandomSessionIdGenerator.php  20-character base64url session ids
    Socket.php                  session: state, heartbeat, upgrade, write buffer
    SocketState.php, CloseReason.php  session state; close reasons with the reference's strings
    ServerOptions.php           pingInterval, pingTimeout, maxPayload, upgradeTimeout, path, cors; fromArray()
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
  BroadcastOperator.php         to/in/except/compress/volatile/emit and the socket helpers, immutable
  Parser/                       socket.io-parser (protocol v5)
    Encoder.php                 Packet → list<string|Binary>: the text part, then one Binary per attachment
    Decoder.php                 one per connection: add(string|Binary): ?Packet, reset()
    Packet.php, PacketType.php, InvalidPacketException.php
  Adapter/
    Adapter.php                 the extension point for other processes
    BroadcastOptions.php        rooms, except and volatile of a broadcast
    InMemoryAdapter.php         local rooms and broadcast; not final, the base of other adapters
    ChannelAdapter.php          cross-process broadcast through workerman/channel
```

### Workerman 5 integration

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
  - Workerman calls `onWebSocketConnected` even when `onWebSocketConnect` already closed the connection.
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
  - a missing or unknown `transport` is code 0;
  - an `Origin` header with invalid characters is code 3;
  - with a `sid`: an unknown session is code 1; over HTTP, a `transport` other than the session's current one is code 3 (this rejects HTTP after the upgrade); `EIO` other than `4` is code 3;
  - without a `sid`: a method other than `GET` is code 2; `transport=websocket` over HTTP, or `transport=polling` on a WebSocket, is code 3; `EIO` other than `4`, or missing, is code 5.

  A WebSocket request is validated before the handshake is answered (`Server::verifyWebSocket()`) and again when the connection is handed over, in case the session closed in between.
- **Handshake:** only via `GET` without `sid` (or WebSocket without `sid`). It answers an `open` packet with `sid`, `upgrades`, `pingInterval`, `pingTimeout` and `maxPayload`. `upgrades` is `["websocket"]` on polling and `[]` on WebSocket. The session id is 15 random bytes in base64url (20 characters), like the reference's `base64id`. A closed session leaves the registry at once, so later requests with its `sid` get code 1.
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
- **CORS:** the `cors.origin` option (`'*'` by default, a list of origins, or `null` to disable), including the `OPTIONS` preflight.
  - The headers go on every polling answer, errors included.
  - With a list, a listed origin is echoed with `Vary: Origin`, and any other origin gets no `Access-Control-Allow-Origin`, so the browser blocks it.
  - The preflight answers 204 with `GET, POST, OPTIONS` and echoes `Access-Control-Request-Headers`.
  - `credentials` is not supported yet. With CORS disabled, `OPTIONS` is an ordinary request and gets code 2.

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
- **Disconnect reasons** (`DisconnectReason`, given to listeners as strings): the Engine.IO close reasons, plus `server namespace disconnect`, `client namespace disconnect` and `forced server close`. `disconnecting` fires while the socket still has its rooms, `disconnect` after it left them.
- **Ids:** a socket id differs from its Engine.IO session id and differs per namespace. Format: 24 lowercase hex characters, which `ChannelAdapter` relies on to recognize sid rooms.
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
- **Acks:** if the last argument of `emit` is a `Closure`, it becomes the ack callback. Received events with an id get an ack `Closure` as their last argument, which answers only once. Acks while broadcasting throw.
  - Ack ids are counted per namespace, as in the reference.
  - With `timeout()`, the callback gets `null` and the answer, or a `TimeoutException` ("operation has timed out"). In that case, its other required parameters get `null`, so `function (?Throwable $err, $answer)` works.
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

- `SocketIO`: `of()`, `on()` (including `workerStart`/`workerStop`), `use()`, `to/in/except`, `emit`, `send`, `compress`, `volatile`, `adapter()`, `attach(Worker)`, `$io->worker`, `$io->sockets` (the `/` namespace).
- `Nsp`: `on('connection')`, `use()`, `to/in/except`, `emit`, `fetchSockets()`, `allSockets()`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()`.
- `Socket`: `id`, `nsp`, `client`, `conn`, `request`, `handshake`, `data`, `rooms`, `connected`, `emit`, `send`, `on`, `join`, `leave`, `to/in/except`, `broadcast`, `volatile`, `compress()`, `timeout()`, `disconnect(bool $close = false)`.
- The broadcast helpers act on the local process only; cross-process delivery goes through the adapter.
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
- `ChannelAdapter` uses `workerman/channel`. It carries over phpsocket.io 3.x's fixes:
  - per-namespace channels (`socket.io#<nsp>#` and `socket.io#<nsp>#<room>#`), with one callback per channel via `Client::on()`;
  - sid rooms are detected by their shape (24 hex characters) and go through the namespace's default channel instead of getting their own;
  - a channel is unsubscribed only once its room is empty in the process;
  - creating the adapter outside the event loop throws a clear error (the right place is `workerStart`).
- With several processes on the same port, clients must use `transports: ['websocket']`, because polling breaks across processes without sticky sessions. Document this in the README.

## Roadmap

1. [x] Scaffold: Composer, Docker, PHPUnit, PHPStan max, phpcs, CI.
2. [x] Engine.IO parser (`Engine\Parser`).
3. [x] `Scheduler`, `Emitter`, `Engine\Socket` and transports, tested with a fake scheduler and fake connections.
4. [x] `Engine\Server`, the Workerman protocols, `WorkermanScheduler` and the Workerman implementations of `HttpExchange` and `WebSocketConnection`, with the official Engine.IO v4 suite running in CI.
5. [x] Socket.IO parser (`Encoder`, `Decoder`, `Binary`).
6. [ ] `SocketIO`, `Nsp`, `Client`, `Socket`, `BroadcastOperator`, `InMemoryAdapter`, with the official Socket.IO v5 suite in CI.
7. [ ] Integration with `socket.io-client@4` in CI.
8. [ ] `ChannelAdapter`, with a multi-process integration test and a scale check.
9. [ ] Complete README, examples, and a migration guide from phpsocket.io 3.x.
10. [ ] First release (`v0.1.0` or `v1.0.0-beta.1`, to be decided).
