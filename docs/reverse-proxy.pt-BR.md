# Atrás de um proxy reverso

[English](reverse-proxy.md) · **Português (Brasil)**

Em produção o servidor costuma ficar atrás de um proxy reverso, como nginx ou HAProxy, que termina o TLS e repassa as conexões. O Socket.IO precisa de três coisas dele: os headers de upgrade do WebSocket, um timeout de leitura maior que o heartbeat e, com vários processos e HTTP long-polling, sticky sessions.

## Um processo

O proxy termina o TLS e repassa `/socket.io/` para o servidor:

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

No lado PHP, escute só no loopback e leia o endereço do cliente no header que o proxy define:

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

- `proxy_read_timeout` precisa ser maior que `pingInterval` mais `pingTimeout`; senão o nginx fecha as conexões quietas e os clientes recebem `transport close`.
- Atrás do proxy, `$socket->handshake['address']` é o endereço do proxy e `secure` é `false`, já que o TLS termina no proxy. Confie em `X-Forwarded-For` e `X-Forwarded-Proto` só quando o proxy os define, como acima.

## Vários processos com HTTP long-polling

Uma sessão de polling é uma série de requests HTTP, e todos precisam chegar ao processo que guarda a sessão; senão o cliente recebe `Session ID unknown` e reconecta em loop. Vários processos na mesma porta, com `reusePort`, não garantem isso: o kernel espalha as conexões. Dê a cada processo a sua porta e deixe o proxy manter cada cliente num deles:

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

O `ChannelAdapter` leva os broadcasts entre os processos, como em [Vários processos](multiple-processes.pt-BR.md); o `RedisAdapter` funciona igual. A opção `cookie` faz o handshake de polling gravar `io=<id da sessão>`, que um proxy pode usar para fixar o cliente.

Com nginx, pelo endereço do cliente:

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

Com HAProxy, pelo cookie `io`, para que clientes atrás de um mesmo endereço também se espalhem pelos processos:

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

Se os clientes conectam com `transports: ['websocket']`, não há polling nem necessidade de sticky sessions: cada WebSocket é uma conexão só, então `reusePort` numa porta basta.
