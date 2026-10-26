# Configuration

**English** · [Português (Brasil)](configuration.pt-BR.md)

## Server options

`new SocketIO($port, $options)` takes these options. Times are in milliseconds.

| Option | Default | Meaning |
|---|---|---|
| `pingInterval` | `25000` | How often the server pings each client. |
| `pingTimeout` | `20000` | How long it waits for the pong before closing the connection. |
| `maxPayload` | `1000000` | Largest message, in bytes; longer HTTP bodies get 413. `maxHttpBufferSize`, the reference's name, is accepted too. |
| `upgradeTimeout` | `10000` | How long an upgrade from polling to WebSocket may take. |
| `connectTimeout` | `45000` | How long a client may stay without joining a namespace. |
| `logger` | `null` | A `Closure(string $scope, string $message)` that gets the debug log; see [Debug log](#debug-log). |
| `path` | `'/socket.io/'` | The URL path; the client's `path` option must match. |
| `transports` | `['polling', 'websocket']` | The transports clients may use. With `['websocket']`, polling requests are refused, so clients need `transports: ['websocket']` too (otherwise they get `xhr poll error`). With `['polling']`, no upgrade is offered. |
| `httpCompression` | `true` | Compresses polling answers of 1 KB or more with gzip or deflate, as the client's `Accept-Encoding` allows, as in the reference. `false` turns it off; `['threshold' => bytes]` changes the size. An answer whose packets were all sent with `compress(false)` stays plain. |
| `cors` | `['origin' => '*']` | `origin` is `'*'`, a list of allowed origins, a `Closure` that decides per request, or `null` to send no CORS headers. `credentials` (`false` by default) lets browsers send cookies. |
| `allowUpgrades` | `true` | With `false`, the handshake offers no upgrade, so clients that start on polling stay on it; clients with `transports: ['websocket']` still connect over WebSocket. |
| `cookie` | `false` | With `true`, the polling handshake sets the cookie `io=<session id>` (`Path=/; HttpOnly; SameSite=Lax`), which a load balancer can use for sticky sessions; see [Behind a reverse proxy](reverse-proxy.md). An array changes its attributes: `name`, `path`, `domain`, `httpOnly`, `secure`, `sameSite`, `maxAge` (seconds). |
| `allowRequest` | `null` | A `Closure` that accepts or refuses each handshake before the session opens. See [Refusing a connection before the handshake](#refusing-a-connection-before-the-handshake). |

Unknown options and wrong types throw `InvalidArgumentException`.

## Cookies across origins

When the page and the server differ in host or port, the browser sends cookies only if the client asks and the server allows it. A typical case is authenticating by a session cookie:

```js
const socket = io("https://example.com:3000", { withCredentials: true });
```

```php
use Marcolin\SocketIO\Engine\Request;

$io = new SocketIO(3000, [
    'cors' => [
        // The page's host, on any port; a list of origins works too.
        'origin' => fn (string $origin, Request $request): bool =>
            parse_url($origin, PHP_URL_HOST) === parse_url('//' . $request->header('Host'), PHP_URL_HOST),
        'credentials' => true,
    ],
]);

$io->on('connection', function (Socket $socket): void {
    $cookie = $socket->handshake['headers']['cookie'] ?? '';
});
```

With `credentials`, `origin` can't be `'*'`: browsers reject that pair, so it throws.

The [session-auth example](../examples/session-auth) puts it together: a page on another port logs in with a PHP session, and a middleware lets in only the sockets of logged-in sessions.

CORS only covers HTTP long-polling: browsers don't apply it to WebSocket, so a page on another site can open a WebSocket to your server, with your cookies if they are `SameSite=None`. To refuse other origins on every transport, check the `Origin` header in a middleware too:

```php
$io->use(function (Socket $socket, Closure $next): void {
    $origin = $socket->handshake['headers']['origin'] ?? '';
    $host = $socket->handshake['headers']['host'] ?? '';
    $sameHost = parse_url($origin, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST);
    $next($sameHost ? null : new ConnectionError('forbidden origin'));
});
```

## Refusing a connection before the handshake

`allowRequest` gets the `Engine\Request` of each handshake, over polling or WebSocket, before any session opens. It returns `true` to accept. `false` or a message refuses with code 4 (`Forbidden`): HTTP 403 with `{"code":4,"message":"..."}` over polling, HTTP 400 with the message over WebSocket. The client gets `connect_error`.

```php
use Marcolin\SocketIO\Engine\Request;

$io = new SocketIO(3000, [
    'allowRequest' => function (Request $request) use ($sessions): bool|string {
        return $sessions->isValid($request->header('cookie') ?? '') ? true : 'invalid session';
    },
]);
```

It runs synchronously in the event loop, so keep it fast: a slow check holds up every connection of the process. An exception closes the connection (with a 500 over polling) and goes to the worker's log.

A middleware does the same job one step later, after the session opens: it may wait for asynchronous work, and its `ConnectionError` reaches the client with a message and data over every transport. With `allowRequest`, the client reads the message only over polling, in `err.context.responseText`; over WebSocket it sees `websocket error`.

## Engine events

`$io->engine` emits events about the HTTP side of each connection, as the reference's `io.engine` does:

```php
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\ResponseHeaders;

// The first answer of each session: the polling handshake, or the 101 of a WebSocket.
$io->engine->on('initial_headers', function (ResponseHeaders $headers, Request $request): void {
    $headers->append('Set-Cookie', 'lb=web-1; Path=/; HttpOnly');
});

// Every successful polling answer and every 101.
$io->engine->on('headers', function (ResponseHeaders $headers, Request $request): void {
    $headers->set('X-Served-By', gethostname());
});

// A refused request, such as an unknown session or a refusal from allowRequest.
$io->engine->on('connection_error', function (Request $request, int $code, string $message): void {
    Worker::log("refused {$request->uri}: $code $message");
});
```

- `ResponseHeaders` has `set()`, `append()` (one line per value, as several `Set-Cookie` need), `get()`, `remove()` and `all()`; names are matched case-insensitively, and a name that isn't a header token or a value with a line break throws.
- Error answers and the CORS preflight go out without the `headers` event.
- The `code` of `connection_error` is the Engine.IO error code (0 to 5) of the answer, and `message` its message: the one `allowRequest` returned, when it refused.

## Debug log

The `logger` option gets what the reference logs with `DEBUG=socket.io:*`: a scope and a message for each step of a connection's life. Without it, nothing is logged.

```php
use Workerman\Worker;

$io = new SocketIO(3000, [
    'logger' => function (string $scope, string $message): void {
        Worker::log("[$scope] $message");
    },
]);
```

```text
[engine] session iZ8v0K2tq_3LFkpbAAAB opened over polling from 203.0.113.7
[socket.io:socket] socket 6f1c0a2b9d3e4f5a6b7c8d9e joined / over session iZ8v0K2tq_3LFkpbAAAB
[engine:socket] session iZ8v0K2tq_3LFkpbAAAB upgraded to websocket
[socket.io:socket] socket 6f1c0a2b9d3e4f5a6b7c8d9e left /: transport close
[engine:socket] session iZ8v0K2tq_3LFkpbAAAB closed: transport close
```

| Scope | What |
|---|---|
| `engine` | a session opened (transport, client address) or a request refused (code, message) |
| `engine:socket` | a session upgraded to WebSocket, or closed (reason) |
| `socket.io:client` | an unknown namespace asked for, no namespace joined in time, an invalid or out-of-turn packet |
| `socket.io:socket` | a socket joined or left a namespace, a middleware refused one, a `use()` middleware stopped an event |
| `socket.io:adapter` | with `ChannelAdapter` or `RedisAdapter`, a server joined or left the cluster |

Filter by scope in the `Closure` to keep only what you need. The messages carry ids and addresses, never event data.

## TLS

Create the worker with the certificate, mark it `ssl`, and attach it:

```php
$worker = new Worker('tcp://0.0.0.0:443', [
    'ssl' => ['local_cert' => '/path/to/cert.pem', 'local_pk' => '/path/to/key.pem', 'verify_peer' => false],
]);
$worker->transport = 'ssl';
$io->attach($worker);
```
