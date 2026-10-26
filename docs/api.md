# API reference

## `SocketIO`

| | |
|---|---|
| `new SocketIO(?int $port = null, array $options = [])` | With a port, it listens on `tcp://0.0.0.0:<port>`. Without one, call `attach()`. |
| `attach(Worker $worker)` | Serves the server on your own `tcp` or `ssl` worker, for another address, several processes or TLS. Call it before `Worker::runAll()`. |
| `$io->worker` | The attached worker, or `null`. |
| `$io->engine` | The Engine.IO server, with the `initial_headers`, `headers` and `connection_error` events; see [Engine events](configuration.md#engine-events). |
| `of(string $name): Nsp` | The namespace with that name, created on first use. |
| `$io->sockets` | The main namespace, `/`. |
| `on('connection', fn (Socket $socket))` | The main namespace's `connection` event; any other event but `workerStart` and `workerStop` is the main namespace's too, as with `serverSideEmit()`. |
| `on('workerStart' \| 'workerStop', fn (Worker $worker))` | Each process starting or stopping. |
| `on('new_namespace', fn (Nsp $nsp))` | A namespace other than `/` was created, such as to give every namespace a middleware. |
| `once()`, `off()`, `listeners()`, `eventNames()`, `removeListener()`, `removeAllListeners()` | As `on()`: the main namespace's listeners, except `workerStart` and `workerStop`. |
| `use(fn (Socket $socket, Closure $next))` | A middleware on `/`. |
| `adapter(fn (Nsp $nsp): Adapter)` | How namespaces keep rooms and broadcast, such as `ChannelAdapter::factory()` or `RedisAdapter::factory()`. `$nsp->adapter()` returns a namespace's adapter, with its [events](#adapter-events). |
| `close()` | Shuts the server down: every socket leaves with `server shutting down`, the connections close, and the worker stops accepting new ones in this process. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `local`, `compress()`, `timeout()` | Broadcasting on `/`, as on a namespace. |
| `serverSideEmit(string $event, ...$args)` | An event to `/` on the other servers; see `Nsp`. |
| `fetchSockets()`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets()` | The socket helpers of `/`, as on a namespace. |

## Namespaces: `Nsp`

| | |
|---|---|
| `on('connection', fn (Socket $socket))` | A socket joined, after the middlewares let it in. `connect` is the same. |
| `use(fn (Socket $socket, Closure $next))` | Runs before each connection, in order. Call `$next()` to let the socket in, or `$next($error)` to refuse it; `$next` may be called later, from an asynchronous callback. A `ConnectionError` also sends its `data`. An exception thrown before `$next` refuses the socket. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `local`, `compress()`, `timeout()` | Broadcasting to the namespace. See [Acknowledgements](#acknowledgements) for `timeout()`. |
| `sockets(): array<string, Socket>` | The connected sockets, by id. |
| `allSockets(): list<string>` | Their ids. |
| `fetchSockets(): list<Socket>`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets(bool $close = false)` | Act on the selected sockets; use `to()` and `except()` first to select. With `ChannelAdapter` or `RedisAdapter`, `socketsJoin()`, `socketsLeave()` and `disconnectSockets()` reach every process; `fetchSockets()` reads this one. |
| `fetchSockets(fn (?Throwable $error, array $sockets))` | The selected sockets of every server: `Socket` objects from this process, a [`RemoteSocket`](#remotesocket) for each one elsewhere. The error is a `TimeoutException`, with the sockets so far, when some server didn't answer within 5 seconds. |
| `serverSideEmit(string $event, ...$args)` | Sends an event to this namespace on the other servers, whose `on($event)` listeners get it. A `Closure` last gets `(?Throwable $error, array $responses)`, one response per other server; the error is a `TimeoutException` after 5 seconds. Needs `ChannelAdapter` or `RedisAdapter`; `connect`, `connection` and `new_namespace` are reserved. See [Several processes](multiple-processes.md#what-crosses-processes). |

## `Socket`

| | |
|---|---|
| `$socket->id` | 24 hexadecimal characters, different from the Engine.IO session id and per namespace. |
| `$socket->handshake` | `headers` (lower-case names), `time`, `address`, `xdomain`, `secure`, `issued` (ms), `url`, `query`, and `auth`, the client's `auth` option. |
| `$socket->data` | A free-form array for your own data. |
| `$socket->rooms`, `$socket->connected`, `$socket->disconnected` | Its rooms (its own id included) and its state. |
| `$socket->nsp`, `$socket->request`, `$socket->conn` | Its namespace, the HTTP request that opened the connection, and the Engine.IO session. |
| `on(string $event, Closure $listener)` | An event from the client. The listener gets the arguments, plus an acknowledgement `Closure` last when the client asked for one; it answers once. |
| `emit(string $event, ...$args)`, `send(...$args)` | Sends an event; a `Closure` as the last argument asks the client to acknowledge it. |
| `once()`, `off()`, `listeners()`, `eventNames()`, `removeListener()`, `removeAllListeners()` | The other listener methods, with the reference's names. |
| `onAny(Closure $listener)`, `prependAny()`, `offAny(?Closure $listener = null)`, `listenersAny()` | Every event from the client, before its own listeners. The listener gets the event name, then the arguments, with the acknowledgement `Closure` last when the client asked for one. `offAny()` without a listener removes them all. |
| `onAnyOutgoing(Closure $listener)`, `prependAnyOutgoing()`, `offAnyOutgoing()`, `listenersAnyOutgoing()` | Every event sent to this client, by `emit()` or a broadcast (from other processes too), before it is written: the event name, then the arguments, without the acknowledgement callback. |
| `use(fn (array $event, Closure $next))` | A middleware for the events from the client, after the `onAny()` listeners and before the event's own; see [Socket middlewares](#socket-middlewares). |
| `timeout(int $ms)`, `volatile`, `compress(bool)` | Emit modifiers, which return a new object. See [Acknowledgements](#acknowledgements). |
| `join(string\|array $rooms)`, `leave(string $room)` | Rooms. |
| `to()`, `in()`, `except()`, `broadcast`, `local` | Broadcasting to other sockets; the socket itself is left out. `local` keeps to this process. |
| `disconnect(bool $close = false)` | Leaves the namespace; with `true`, closes the whole connection. |

Events: `disconnecting` and `disconnect` get `(string $reason, ?Throwable $description)`; `disconnecting` still sees the rooms. The reasons are `transport close`, `transport error`, `ping timeout`, `parse error`, `forced close`, `server namespace disconnect`, `client namespace disconnect`, `forced server close` and `server shutting down`. `error` gets a `Throwable` when the client sent a packet that breaks the protocol, just before the connection closes, or when a `use()` middleware stopped an event.

### Session events

`$socket->conn` is the Engine.IO session under the socket, shared by its namespaces. Its events, as the reference's:

```php
use Marcolin\SocketIO\Engine\Packet;

$socket->conn->on('upgrade', function (): void {
    // The connection moved from HTTP long-polling to WebSocket.
});
$socket->conn->on('packet', function (Packet $packet): void {});        // each packet from the client
$socket->conn->on('packetCreate', function (Packet $packet): void {});  // each packet to the client
```

- `packet` and `packetCreate` get an `Engine\Packet`, with its `type`, `data` and `binary`. `packetCreate` covers the messages and pings the server writes, before they are buffered; the handshake's `open` goes out before any listener can be added.
- `heartbeat` fires when the client answers a ping, `drain` when the write buffer was handed to the transport, `upgrading` and `upgrade` around the move to WebSocket, and `close` with the `CloseReason`.

### Socket middlewares

`use()` middlewares run, in order, for each event the socket receives. Each gets the event as `[name, ...args]`, with the acknowledgement `Closure` last when the client asked for one, and lets it through with `$next()` or stops it with `$next($error)`:

```php
$socket->use(function (array $event, Closure $next) use ($socket): void {
    [$name] = $event;   // then the arguments, and the acknowledgement Closure last when asked
    $next(isAllowed($socket, $name) ? null : new RuntimeException('unauthorized event'));
});

$socket->on('error', function (Throwable $error) use ($socket): void {
    $socket->emit('refused', $error->getMessage());
});
```

- A stopped event reaches no listener, and the socket emits `error` with the error; the socket stays connected, so the app decides what to do.
- `$next` may be called later, from an asynchronous callback; only its first call counts. An exception thrown before `$next` stops the event the same way.
- Taking the event by reference, `array &$event`, lets a middleware change the arguments the next middlewares and the listeners get.

The names `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` and `removeListener` are reserved: emitting them throws.

## `RemoteSocket`

A socket on another server, from `fetchSockets()` with a callback:

```php
$io->in('room1')->fetchSockets(function (?Throwable $error, array $sockets): void {
    foreach ($sockets as $socket) {   // Socket here, RemoteSocket on another server
        $socket->emit('hello', $socket->data['name'] ?? null);
    }
});
```

| | |
|---|---|
| `id`, `handshake`, `rooms`, `data` | Copies, as the socket's server had them. `handshake` and `data` cross as JSON, so objects arrive as arrays. |
| `emit(string $event, ...$args)` | Sends it an event. |
| `timeout(int $ms)` | Lets `emit()` take a callback, which gets `(?Throwable $error, mixed $answer)` as with `Socket::timeout()`. Returns a new object. |
| `join(string\|array $rooms)`, `leave(string $room)` | Its rooms, on its server. |
| `disconnect(bool $close = false)` | Disconnects it from the namespace; with `true`, closes its connection. |

## Acknowledgements

```php
// The server asks; the callback gets the client's answer.
$socket->emit('question', 'colour?', function (mixed $answer): void {});

// With a timeout, the callback gets null and the answer, or a TimeoutException.
$socket->timeout(5000)->emit('question', 'colour?', function (?Throwable $error, mixed $answer): void {});

// The client asks; the last argument answers it.
$socket->on('sum', function (array $numbers, Closure $ack): void {
    $ack(array_sum($numbers));
});
```

PHP has no promises, so the reference's `emitWithAck()` and `serverSideEmitWithAck()` map to a callback: `$socket->timeout(5000)->emit('event', $callback)` and `$io->serverSideEmit('event', $callback)`.

A broadcast takes an acknowledgement callback after `timeout()`. The callback gets one answer per socket it reached, in the order they arrive: the first argument of each client's acknowledgement.

```php
$io->to('room1')->timeout(5000)->emit('vote', function (?Throwable $error, array $answers): void {
    // $error is null once every socket answered,
    // or a TimeoutException, with the answers that arrived in time.
});
```

- Without `timeout()`, passing a `Closure` throws, since a socket that never answers would keep the callback waiting forever.
- With no socket to reach, the callback runs at once with `[]`.
- With `volatile`, a socket skipped because its transport was busy still counts, so the wait ends with the timeout.
- With `ChannelAdapter` or `RedisAdapter`, it reaches the sockets of every process: the callback runs once every server reported how many sockets it reached and all of them answered.

## Broadcasting: `BroadcastOperator`

`to()`, `in()`, `except()`, `volatile` and `local` return an immutable operator:

```php
$io->emit('news', $data);                         // every socket of "/"
$io->to('room1')->to('room2')->emit('news');      // sockets in either room, once each
$io->except('muted')->emit('news');               // everyone but the sockets in "muted"
$io->of('/admin')->to($socketId)->emit('ping');   // one socket, by its id
$socket->broadcast->emit('joined', $socket->id);  // everyone but this socket
$socket->to('room1')->emit('typing');             // the room, but not this socket
$io->volatile->emit('position', $x, $y);          // skipped where the transport is busy
$io->local->emit('reload');                       // this process only, whatever the adapter
```

`compress(false)` keeps a broadcast or an emit uncompressed; by default, big polling answers are compressed (see the `httpCompression` option).

## Adapter events

Each namespace's adapter tells when its rooms change, as the reference's adapter does, with no option to turn on:

```php
$adapter = $io->of('/')->adapter();

$adapter->on('create-room', function (string $room): void {});
$adapter->on('delete-room', function (string $room): void {});
$adapter->on('join-room', function (string $room, string $socketId): void {});
$adapter->on('leave-room', function (string $room, string $socketId): void {});
```

- `create-room` comes before the first `join-room` of a room, and `delete-room` after its last `leave-room`.
- A socket's own room, its id, counts too: connecting creates and joins it, and disconnecting leaves and deletes it.
- They report the sockets of the current process, with every adapter: with several processes, each one sees its own sockets' rooms.

## Errors

- A middleware refuses a connection with `$next(new ConnectionError($message, $data))`: the client's `connect_error` gets `message` and `data`.
- A client packet that breaks the protocol closes its connection.
- An exception from your listeners closes the connection it came from and is logged with `Worker::log`; the worker keeps running.

## Data: JSON and binary

- JSON objects arrive as PHP associative arrays. PHP can't tell `{}` from `[]`, so send an empty object as `new stdClass()`.
- Binary data arrives as `Marcolin\SocketIO\Binary`, wherever it is in the data. Wrap bytes in `new Binary($bytes)` to send them; the client gets a `Buffer` or an `ArrayBuffer`.
- Strings must be valid UTF-8; for other bytes, use `Binary`.
