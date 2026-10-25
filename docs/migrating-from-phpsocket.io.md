# Migrating from phpsocket.io

**English** · [Português (Brasil)](migrating-from-phpsocket.io.pt-BR.md)

For applications on [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io) 2.x or 3.0. The server-side API keeps phpsocket.io's shape, so most code moves with small edits. The real change is on the client side.

## 1. Plan the client switch

phpsocket.io serves `socket.io-client` 1.3 to 2.x (Engine.IO protocol v3). This server serves `socket.io-client` v3 and v4 (Engine.IO v4, Socket.IO v5). The protocols are incompatible both ways: an old client can't connect here, and a new client can't connect to phpsocket.io.

So the clients and the server change together. A safe path is to run both servers side by side, each on its own port or path, ship clients that use the new one, and retire phpsocket.io once no old client is left.

On the client side, follow [Socket.IO's migration from 2.x to 3.0](https://socket.io/docs/v4/migrating-from-2-x-to-3-0/). The main points:

- `io()` connects to `/` explicitly: there is no implicit connection to the main namespace anymore.
- Credentials go in the `auth` option, and the server reads them in `$socket->handshake['auth']`, instead of in the query string.
- With several server processes, connect with `transports: ['websocket']`.
- **Cookies**: the 2.x client sends cookies cross-origin by default, and phpsocket.io allows any origin with credentials. The v3/v4 client sends them only with `withCredentials: true`, and this server needs `'cors' => ['origin' => ..., 'credentials' => true]` with a list of origins or a `Closure` (see [Cookies across origins](../README.md#cookies-across-origins)). Without both, a cookie-based login silently stops working.

## 2. Requirements and Composer

| | phpsocket.io | socket.io-server |
|---|---|---|
| PHP | 7.4 or later | 8.1 or later |
| Workerman | 4.x | 5.2 |
| Package | `workerman/phpsocket.io` | `marcosmarcolin/socket.io-server` |
| Namespace | `PHPSocketIO\` | `Marcolin\SocketIO\` |

`workerman/channel` stays the way to run several processes, and is still optional.

## 3. What changes in your code

| phpsocket.io | socket.io-server |
|---|---|
| `use PHPSocketIO\SocketIO;` | `use Marcolin\SocketIO\SocketIO;` |
| `new SocketIO(2026)` | the same |
| `new SocketIO(2026, ['ssl' => [...]])`: the second argument is the socket context | the second argument holds [server options](../README.md#server-options). For TLS, create the worker with the context, set `$worker->transport = 'ssl'`, and call `$io->attach($worker)` ([TLS](../README.md#tls)) |
| `['host' => '::1']` | `$io->attach(new Worker('tcp://[::1]:2026'))` |
| `['origins' => 'http://a.com:80']`, `$io->origins(...)` | `['cors' => ['origin' => ['http://a.com']]]`, plus `'credentials' => true` for cookies |
| `['nsp' => MyNsp::class, 'socket' => MySocket::class]` | not supported: the classes are final. Use middlewares and `$socket->data` |
| `$io->worker->count = 4` | still works. Add `$io->worker->reusePort = true` so the kernel spreads connections |
| `$io->adapter(ChannelAdapter::class)` with `ChannelAdapter::$ip` and `::$port` | `$io->adapter(ChannelAdapter::factory($ip, $port))`, still in `workerStart`, with `use Marcolin\SocketIO\Adapter\ChannelAdapter;` |
| `$socket->username = 'ana'` (dynamic properties) | `$socket->data['username'] = 'ana'` |
| `function ($data, $callback) { if (is_callable($callback)) {...} }` | the acknowledgement is a `Closure`, always the last argument: `function ($data, ?Closure $ack = null)` |
| `$socket->emit('event', $data, $callback)` with any callable | the callback must be a `Closure` |
| `$socket->emit('disconnect')`, `'connect'`, `'error'`...: runs the local listeners | `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` and `removeListener` throw; `error` is sent to the client like any event |
| `$socket->join('room')` | the same, and it also takes a list: `$socket->join(['a', 'b'])` |
| `$socket->rooms`: a map, `room => room` | a list: `['<socket id>', 'room']` |
| `$socket->to('room')` returns the socket, with flags set until the next `emit` | returns a new, immutable `BroadcastOperator`. `$socket->to('room')->emit(...)` reads the same |
| `$socket->handshake`: no `auth`; `issued` in seconds | adds `auth`; `issued` is in milliseconds; header names are lower case |
| `$socket->conn->remoteAddress` | `$socket->handshake['address']` or `$socket->request->remoteAddress` |
| `$nsp->clients(function ($sids) {...})` | `$nsp->allSockets()` returns the ids; `$nsp->to('room')->allSockets()` for a room |
| `$nsp->sockets` (array) | `$nsp->sockets()` |
| acknowledgements while broadcasting throw | they work after `timeout()`, and the callback gets every answer: `$io->timeout(5000)->emit('x', function (?Throwable $error, array $answers) {})`; without `timeout()` they still throw |

## 4. What is new

- **Middlewares**: `$io->use()` and `$io->of('/admin')->use()` run before each connection. Refuse with `$next(new ConnectionError('message', $data))`, and the client's `connect_error` gets both.
- **Timeouts**: `$socket->timeout(5000)->emit('question', function (?Throwable $error, $answer) {...})`.
- **`except()`**, `fetchSockets()`, `socketsJoin()`, `socketsLeave()` and `disconnectSockets()` on namespaces and broadcasts.
- **Binary data**: `Marcolin\SocketIO\Binary`, in both directions, anywhere in the data.
- **`ChannelAdapter`** publishes each broadcast once, so a socket in several targeted rooms gets it once.

## 5. What is gone

- `socket.io-client` 1.x and 2.x, and JSONP polling.
- Custom `Nsp` and `Socket` classes.
- `PHPSocketIO\Debug`, already removed in phpsocket.io 3.0.
