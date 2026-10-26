# Vários processos

[English](multiple-processes.md) · **Português (Brasil)**

O broadcast chega aos outros processos por um adapter, definido no `workerStart` de cada processo:

- `ChannelAdapter`, sobre um servidor [workerman/channel](https://github.com/walkor/channel): nada mais para rodar, bom para uma máquina.
- `RedisAdapter`, sobre um servidor Redis: para várias máquinas, ou quando você já usa Redis. Não precisa de nenhum pacote extra.

Os dois funcionam do mesmo jeito: cada broadcast é codificado uma vez e publicado uma vez, e cada processo o entrega aos seus próprios sockets.

## workerman/channel

```sh
composer require workerman/channel
```

```php
<?php

use Channel\Server as ChannelServer;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// The channel server, in a process of its own.
new ChannelServer('127.0.0.1', 2206);

$io = new SocketIO(options: ['cors' => ['origin' => ['https://example.com']]]);

// The adapter connects to the channel server, which needs each process's event loop.
$io->on('workerStart', function () use ($io): void {
    $io->adapter(ChannelAdapter::factory('127.0.0.1', 2206));
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->count = 4;
$worker->reusePort = true;
$io->attach($worker);

Worker::runAll();
```

## Redis

```php
<?php

use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(options: ['cors' => ['origin' => ['https://example.com']]]);

// The adapter connects to Redis, which needs each process's event loop. It reconnects by itself.
$io->on('workerStart', function () use ($io): void {
    $io->adapter(RedisAdapter::factory('redis://:password@10.0.0.5:6379'));
});

$worker = new Worker('tcp://0.0.0.0:3000');
$worker->count = 4;
$worker->reusePort = true;
$io->attach($worker);

Worker::runAll();
```

Todos os argumentos são opcionais:

```php
RedisAdapter::factory(
    url: 'redis://127.0.0.1:6379',   // redis://[[user]:password@]host[:port]
    key: 'socket.io',                // the first part of every channel name
    onError: null,                   // fn (Throwable $error): connection failures and Redis errors
);
```

- A URL padrão é `redis://127.0.0.1:6379`. Caracteres reservados no usuário ou na senha vão com percent-encoding; um número de banco é aceito e ignorado, porque os canais de publish e subscribe são compartilhados por todos os bancos. TLS (`rediss://`) ainda não é suportado.
- Servidores de apps diferentes no mesmo Redis precisam de `key`s diferentes, senão recebem os broadcasts uns dos outros.
- Quando o Redis está inacessível, o adapter tenta de novo a cada segundo e informa a falha uma vez ao `onError` (`Worker::log` por padrão). Até 1.000 publicações esperam a conexão; mensagens publicadas enquanto um processo estava desconectado se perdem, como sempre no pub/sub do Redis.

## Limites

- Os clientes precisam conectar com `transports: ['websocket']`: o polling exige que todos os requests de uma sessão cheguem ao mesmo processo, o que vários processos na mesma porta não garantem. Definir `'transports' => ['websocket']` também no servidor recusa o polling de vez. Entre máquinas, um load balancer com sticky sessions permite o polling.
- Só o broadcast atravessa processos (`emit`, `to`, `except`, `broadcast`). `fetchSockets`, `allSockets`, `socketsJoin`, `socketsLeave` e `disconnectSockets` agem no processo atual.
- Broadcasts com acknowledgement (`timeout()->emit(..., $callback)`) ainda não atravessam processos: os dois adapters lançam exceção.
