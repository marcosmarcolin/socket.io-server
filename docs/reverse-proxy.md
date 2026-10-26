# Behind a reverse proxy

**English** · [Português (Brasil)](reverse-proxy.pt-BR.md)

In production the server usually sits behind a reverse proxy, such as nginx or HAProxy, which ends TLS and forwards the connections. Socket.IO needs three things from it: the WebSocket upgrade headers, a read timeout longer than the heartbeat, and, with several processes and HTTP long-polling, sticky sessions.

## One process

The proxy ends TLS and forwards `/socket.io/` to the server:

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    ssl_certificate     /etc/ssl/example.com.pem;
    ssl_certificate_key /etc/ssl/example.com.key;

    location /socket.io/ {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        # Longer than pingInterval + pingTimeout (45 s by default).
        proxy_read_timeout 90s;
    }
}
```

On the PHP side, listen on the loopback only, and read the client's address from the header the proxy sets:

```php
// Only the proxy reaches it.
$worker = new Worker('tcp://127.0.0.1:3000');
$io->attach($worker);

$io->on('connection', function (Socket $socket): void {
    // The proxy's address; the client's is the first of X-Forwarded-For, set by your proxy.
    $forwarded = $socket->handshake['headers']['x-forwarded-for'] ?? '';
    $clientIp = trim(explode(',', $forwarded)[0]) ?: $socket->handshake['address'];
});
```

- `proxy_read_timeout` must be longer than `pingInterval` plus `pingTimeout`, or nginx closes quiet connections and the clients see `transport close`.
- Behind the proxy, `$socket->handshake['address']` is the proxy's address and `secure` is `false`, since TLS ends at the proxy. Trust `X-Forwarded-For` and `X-Forwarded-Proto` only when the proxy sets them, as above.

## Several processes with HTTP long-polling

A polling session is a series of HTTP requests, and every one of them must reach the process that holds the session; otherwise the client gets `Session ID unknown` and reconnects in a loop. Several processes on one port, with `reusePort`, can't promise that: the kernel spreads the connections. Give each process its own port and let the proxy keep each client on one:

```php
<?php

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

new ChannelServer('127.0.0.1', 2206);

// One process per port, so the proxy can keep each client on one of them.
foreach ([3001, 3002, 3003, 3004] as $port) {
    $io = new SocketIO(options: ['cookie' => true]);
    $io->on('workerStart', function () use ($io): void {
        $io->adapter(ChannelAdapter::factory('127.0.0.1', 2206));
    });
    $io->on('connection', function (Socket $socket) use ($io): void {
        $socket->on('chat', function (string $text) use ($io): void {
            $io->emit('chat', $text);
        });
    });
    $io->attach(new Worker('tcp://127.0.0.1:' . $port));
}

Worker::runAll();
```

`ChannelAdapter` carries the broadcasts between the processes, as in [Several processes](multiple-processes.md); `RedisAdapter` works the same. The `cookie` option makes the polling handshake set `io=<session id>`, which a proxy can use to stick.

With nginx, by the client's address:

```nginx
upstream socket_io {
    # The same client address always reaches the same process.
    hash $remote_addr consistent;
    server 127.0.0.1:3001;
    server 127.0.0.1:3002;
    server 127.0.0.1:3003;
    server 127.0.0.1:3004;
}

server {
    listen 80;

    location /socket.io/ {
        proxy_pass http://socket_io;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 90s;
    }
}
```

With HAProxy, by the `io` cookie, so clients behind one address still spread over the processes:

```haproxy
defaults
    mode http
    timeout connect 5s
    timeout client 90s
    timeout server 90s

frontend socket_io
    bind *:80
    default_backend processes

backend processes
    balance roundrobin
    # Keeps a client on the process whose name prefixes the io cookie.
    cookie io prefix indirect nocache
    server p1 127.0.0.1:3001 cookie p1
    server p2 127.0.0.1:3002 cookie p2
    server p3 127.0.0.1:3003 cookie p3
    server p4 127.0.0.1:3004 cookie p4
```

If the clients connect with `transports: ['websocket']`, there is no polling and no need for sticky sessions: each WebSocket is one connection, so `reusePort` on one port is enough.
