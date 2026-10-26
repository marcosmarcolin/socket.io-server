# Vários processos

[English](multiple-processes.md) · **Português (Brasil)**

O broadcast e os helpers de sockets chegam aos outros processos por um adapter, definido no `workerStart` de cada processo:

- `ChannelAdapter`, sobre um servidor [workerman/channel](https://github.com/walkor/channel): nada mais para rodar, bom para uma máquina.
- `RedisAdapter`, sobre um servidor Redis: para várias máquinas, ou quando você já usa Redis. Não precisa de nenhum pacote extra.

Os dois funcionam do mesmo jeito: cada broadcast é codificado uma vez e publicado uma vez, e cada processo o entrega aos seus próprios sockets.

## O que atravessa processos

- Broadcasts: `emit`, `to`, `except`, `broadcast`, `volatile` e `timeout()->emit(..., $callback)`, cujo callback recebe as respostas de todos os processos.
- `socketsJoin()`, `socketsLeave()` e `disconnectSockets()`: `$io->in($userRoom)->disconnectSockets()` desconecta um usuário onde quer que estejam os sockets dele.
- `fetchSockets()` com callback: os sockets de todos os servidores, como `RemoteSocket` quando estão em outro lugar (veja a [referência da API](api.pt-BR.md#remotesocket)).
- `serverSideEmit()`: eventos de um servidor para os outros, que escutam com `on()` no mesmo namespace. Um `Closure` no fim recebe uma resposta por servidor:

```php
// On every server: answers the others.
$io->on('ping', function (string $from, Closure $ack): void {
    $ack(gethostname());
});

// On one server: asks the others, and gets one answer per server.
$io->serverSideEmit('ping', 'web-1', function (?Throwable $error, array $hosts): void {
    // $error is a TimeoutException when some server didn't answer within 5 seconds.
});
```

Cada servidor sabe quantos outros rodam o namespace pelos heartbeats deles, enviados depois de 5 segundos sem publicar nada; um servidor em silêncio por 10 segundos, ou que fechou, deixa de contar. Os dados do `serverSideEmit()` seguem as mesmas regras do `emit()`, incluindo `Binary`.

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

## Enviando do seu app PHP: o Emitter

O `Emitter` envia para os clientes a partir de qualquer código PHP que não seja um servidor Socket.IO, como um request do PHP-FPM no seu app. Ele publica no Redis o que um servidor publicaria, e os servidores com o `RedisAdapter` entregam aos seus sockets:

```php
use Marcolin\SocketIO\Emitter;

$emitter = Emitter::redis('redis://127.0.0.1:6379');

$emitter->emit('announcement', 'Maintenance at 22:00');          // everyone on "/"
$emitter->to('user:42')->emit('order-approved', ['id' => 1001]); // one room, on every server
$emitter->of('/admin')->except('muted')->emit('alert', $alert);
$emitter->in('user:42')->disconnectSockets();                     // log a user out everywhere
$emitter->serverSideEmit('cache-clear', 'users');                 // the servers' on('cache-clear')
```

- Usa I/O bloqueante, então não precisa de event loop. Conecta no primeiro envio e mantém a conexão; `Emitter::redis($url, $key, $timeout)` recebe a URL e a `key` dos servidores, e quanto esperar pelo Redis, 5 segundos por padrão. Falhas lançam `RuntimeException`.
- `of()`, `to()`, `in()`, `except()` e `volatile` devolvem um emitter novo, então um pode ser guardado e reaproveitado.
- Ele só envia: sem acknowledgements e sem `fetchSockets()`, como os pacotes de emitter da referência. Os dados seguem as regras do `emit()`, incluindo `Binary`.
- Os servidores não contam um emitter como servidor, então ele nunca os faz esperar por respostas.
- O [exemplo emitter](../examples/emitter) tem um endpoint PHP comum que envia notificações para as abas abertas.

## Limites

- Os clientes precisam conectar com `transports: ['websocket']`: o polling exige que todos os requests de uma sessão cheguem ao mesmo processo, o que vários processos na mesma porta não garantem. Definir `'transports' => ['websocket']` também no servidor recusa o polling de vez. Entre máquinas, um load balancer com sticky sessions permite o polling.
- `fetchSockets()` sem callback e `allSockets()` leem o processo atual.
- `local` mantém qualquer um dos itens acima no processo atual: `$io->local->emit('reload')`, `$io->local->disconnectSockets()`.
