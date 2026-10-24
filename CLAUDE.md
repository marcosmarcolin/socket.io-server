# socket.io-server

A **Socket.IO v4** server for PHP, built on **Workerman 5**. Composer package `marcosmarcolin/socket.io-server`, namespace `MarcosMarcolin\SocketIO\`.

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
   - the official **Engine.IO v4 suite**, against a pure Engine.IO server on `/engine.io/` that echoes every message back;
   - the official **Socket.IO v5 suite**, against the reference server described in the spec: `pingInterval` 300, `pingTimeout` 200, `connectTimeout` 1000, a `/custom` namespace, and the `auth`, `message-back` and `message-with-ack` handlers.
4. **The official client works:** an integration test with `socket.io-client@4` (pinned version) covers polling, WebSocket, upgrade, namespaces, rooms, acks, binary and multi-process.
5. **The docs are current:** README, CLAUDE.md and examples match the behavior.

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
- Magic only where the public API requires it and it's documented: `__get` for `broadcast` and `volatile`, annotated with `@property-read`.
- PHPStan at level `max`. No new `ignoreErrors` without a written justification.
- Short comments, one line, only to explain why.
- No `echo`/`print`. Errors become exceptions or `error` events.
- No production dependencies besides Workerman. `workerman/channel` is optional (in `suggest`).
- Everything in English: code, comments, docs, commits, PRs.

## Verification

Never run PHP, Composer, PHPUnit, phpcs or PHPStan on the host. Always use Docker:

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint
```

## Workflow

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
    Server.php                  routes HTTP and WebSocket, handshake, sessions
    Socket.php                  session: state, heartbeat, upgrade, write buffer
    ServerOptions.php           pingInterval, pingTimeout, maxPayload, path, CORS...
    Transport/Transport.php     base transport (Emitter)
    Transport/Polling.php       pending GET, POST, noop on upgrade
    Transport/WebSocket.php     one packet per frame, text or binary
    Protocol/HttpOrWebSocket.php  ProtocolInterface: Workerman HTTP, or switch to WebSocket
    Protocol/WebSocket.php      extends Workerman\Protocols\Websocket, exposes the opcode
    Parser.php, Packet.php, PacketType.php, InvalidPacketException.php
    Scheduler.php, WorkermanScheduler.php
  SocketIO.php                  Socket.IO server (public API)
  Nsp.php                       namespace
  Socket.php                    user-facing socket
  Client.php                    one Engine.IO connection and its per-namespace sockets
  BroadcastOperator.php         to/in/except/compress/volatile/emit, immutable
  Parser/                       socket.io-parser (protocol v5)
    Encoder.php, Decoder.php, Packet.php, PacketType.php
  Adapter/
    InMemoryAdapter.php         local rooms and broadcast
    ChannelAdapter.php          cross-process broadcast through workerman/channel
```

### Workerman 5 integration

- **One port for HTTP and WebSocket.** `Protocol\HttpOrWebSocket` inspects the first request's headers:
  - with `Upgrade: websocket`, it switches `$connection->protocol` to `Protocol\WebSocket` and delegates to it (`TcpConnection` re-reads `protocol` for every package);
  - otherwise it delegates to `Workerman\Protocols\Http`.
- **Text or binary frames.** Workerman's `Websocket` doesn't tell `onMessage` whether a frame was text or binary. `Protocol\WebSocket` overrides `decode()`, which receives the raw frame, and records the opcode in a static `WeakMap`. Sending binary uses Workerman's own `websocketType` mechanism, inside that class only.
- **Worker callbacks.** The WebSocket handshake arrives through the worker's `onWebSocketConnect`, HTTP requests and frames through `onMessage`, and connection closes through `onClose`.
- **Workerman 5 pitfalls:**
  - An exception inside `onWebSocketConnect` calls `Worker::stopAll(250)` and **kills the worker**. Never throw there: reject by closing the connection.
  - Workerman's files use `strict_types`, so `input()` must return an `int`.
  - Exceptions in `onMessage` are caught by Workerman and don't kill the worker. Handle them anyway.

## Protocol behavior

### Engine.IO

- **Default path:** `/socket.io/`. Required query: `EIO=4`, `transport=polling|websocket`, and `sid` after the handshake.
- **HTTP errors** return 400 with a JSON body `{"code":N,"message":"..."}`, using Node's codes:

  | Code | Message |
  |---|---|
  | 0 | Transport unknown |
  | 1 | Session ID unknown |
  | 2 | Bad handshake method |
  | 3 | Bad request |
  | 4 | Forbidden |
  | 5 | Unsupported protocol version |

- **Handshake:** only via `GET` without `sid` (or WebSocket without `sid`). It answers an `open` packet with `sid`, `upgrades`, `pingInterval`, `pingTimeout` and `maxPayload`. `upgrades` is `["websocket"]` on polling and `[]` on WebSocket.
- **Heartbeat:** the **server** sends `ping` every `pingInterval`. Without a `pong` within `pingTimeout`, the session closes with `ping timeout`.
- **Polling:**
  - a `GET` waits until there are packets to send; a `POST` answers `ok`;
  - a second concurrent `GET` gets 400, the pending one gets `1` (close), and the session closes;
  - a pending `GET` cancelled by the client (TCP connection closed) closes the session;
  - a received `close` packet answers the pending `GET` with `6` (noop), and the session closes;
  - an invalid payload gets 400 and closes the session.
- **Upgrade:**
  1. a WebSocket arrives with the `sid` of a polling session;
  2. the client sends `2probe` and the server answers `3probe`;
  3. the pending `GET`, or the next one, gets `6`;
  4. on `5`, the WebSocket becomes the active transport and the buffer is flushed through it.

  After the upgrade, HTTP requests or another WebSocket with the same `sid` are rejected. If the upgrade doesn't complete within `upgradeTimeout` (10 s), the WebSocket is closed.
- **Polling payload:** packets separated by `\x1e`; binary as `b` + base64. Over WebSocket, binary goes as a raw binary frame.
- **CORS:** the `cors.origin` option (`'*'` by default, a list of origins, or `null` to disable), including the `OPTIONS` preflight.

### Socket.IO

- **No implicit connection:** the client must send `CONNECT` for each namespace, including `/`. Without a `CONNECT` within `connectTimeout` (45 s), the connection closes.
- **Answering `CONNECT`:** success answers `CONNECT` with `{"sid":"<id>"}`. An unknown namespace answers `CONNECT_ERROR` with `{"message":"Invalid namespace"}`, and a middleware error answers `CONNECT_ERROR` with `{"message":...,"data":...}`.
- **`CONNECT` payload:** becomes `$socket->handshake['auth']`.
- **Ids:** a socket id differs from its Engine.IO session id and differs per namespace. Format: 24 lowercase hex characters, which `ChannelAdapter` relies on to recognize sid rooms.
- **Strict parser validation** (any violation closes the connection):
  - the type must be 0 to 6;
  - binary types require `N-`;
  - the namespace runs until `,`;
  - an ack id is digits only;
  - a `CONNECT` payload must be an object;
  - an `EVENT` must be a non-empty array whose name is a string or number;
  - an `ACK` must be an array;
  - a `DISCONNECT` has no payload.
- **Binary:**
  - incoming attachments arrive as `Binary` and placeholders are rebuilt;
  - outgoing, any `Binary` in the data turns the packet into `BINARY_EVENT`/`BINARY_ACK` with placeholders.
- **JSON:** incoming data is decoded to PHP arrays. PHP can't tell an empty `{}` from `[]`, so send an empty object with `new stdClass()` or `(object) []`.
- **Acks:** if the last argument of `emit` is a `Closure`, it becomes the ack callback. Received events with an id get an ack `Closure` as their last argument, which answers only once. Acks while broadcasting throw.
- **Reserved names:** `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` and `removeListener` can't be emitted to the client; doing so throws.

## Public API (target)

It keeps phpsocket.io's shape, to ease migration, plus the v4 features.

```php
use MarcosMarcolin\SocketIO\SocketIO;
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
3. [ ] `Scheduler`, `Emitter`, `Engine\Socket` and transports, tested with a fake scheduler and fake connections.
4. [ ] `Engine\Server` and the Workerman protocols, with the official Engine.IO v4 suite running in CI.
5. [ ] Socket.IO parser (`Encoder`, `Decoder`, `Binary`).
6. [ ] `SocketIO`, `Nsp`, `Client`, `Socket`, `BroadcastOperator`, `InMemoryAdapter`, with the official Socket.IO v5 suite in CI.
7. [ ] Integration with `socket.io-client@4` in CI.
8. [ ] `ChannelAdapter`, with a multi-process integration test and a scale check.
9. [ ] Complete README, examples, and a migration guide from phpsocket.io 3.x.
10. [ ] First release (`v0.1.0` or `v1.0.0-beta.1`, to be decided).
