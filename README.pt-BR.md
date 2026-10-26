# socket.io-server

[![CI](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/marcosmarcolin/socket.io-server)](https://packagist.org/packages/marcosmarcolin/socket.io-server)
[![Downloads](https://img.shields.io/packagist/dt/marcosmarcolin/socket.io-server)](https://packagist.org/packages/marcosmarcolin/socket.io-server)
[![PHP version](https://img.shields.io/packagist/dependency-v/marcosmarcolin/socket.io-server/php)](https://packagist.org/packages/marcosmarcolin/socket.io-server)

[English](README.md) · **Português (Brasil)**

Um servidor [Socket.IO](https://socket.io) v4 para PHP, construído sobre o [Workerman](https://github.com/walkor/workerman).

Ele fala os protocolos atuais, Engine.IO v4 e Socket.IO v5, então navegadores e apps usam o `socket.io-client` oficial v3 ou v4. Ele passa nas suítes oficiais de conformidade do Engine.IO e do Socket.IO, e o CI o testa contra o cliente oficial.

> **Versão 0.x.** A API ainda pode mudar entre versões menores até a 1.0; cada mudança está no [changelog](CHANGELOG.md).

## Recursos

- HTTP long-polling e WebSocket na mesma porta, com o upgrade de polling para WebSocket.
- Namespaces, middlewares, salas, acknowledgements (também em broadcasts com timeout), dados binários e listeners para todos os eventos.
- Vários processos e máquinas, pelo [workerman/channel](https://github.com/walkor/channel) ou pelo Redis.
- CORS com cookies, um gancho para recusar conexões antes do handshake, e TLS.
- Uma API no formato da do [phpsocket.io](https://github.com/walkor/phpsocket.io), para facilitar a migração.

## Requisitos

- PHP 8.1 ou mais recente, com a extensão `json`, e `pcntl` e `posix` para rodar o Workerman.
- [Workerman](https://github.com/walkor/workerman) 5.2.
- No cliente: `socket.io-client` v3 ou v4. Para os clientes 1.x e 2.x, use o [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Instalação

```sh
composer require marcosmarcolin/socket.io-server
```

## Início rápido

`server.php`:

```php
<?php

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(3000, ['cors' => ['origin' => ['https://example.com']]]);

$io->on('connection', function (Socket $socket) use ($io): void {
    $socket->join('lobby');
    $socket->emit('hello', 'world');

    // A Closure as the last argument is the client's acknowledgement callback.
    $socket->on('chat', function (string $message, ?Closure $ack = null) use ($io): void {
        $io->to('lobby')->emit('chat', $message);
        if ($ack !== null) {
            $ack('delivered');
        }
    });
});

Worker::runAll();
```

Rode com `php server.php start` (`start -d` para rodar em segundo plano, `stop`, `restart`, `status`: os comandos de sempre do Workerman). Depois, no cliente:

```js
import { io } from "socket.io-client";

const socket = io("http://localhost:3000");

socket.on("hello", (word) => console.log(word));
socket.on("chat", (message) => console.log(message));
socket.emit("chat", "hi", (status) => console.log(status)); // "delivered"
```

## Exemplos

Cada um roda com `php examples/<nome>/start.php start`; depois abra http://localhost:3001.

| Exemplo | Mostra |
|---|---|
| [chat](examples/chat) | uma sala de chat: nomes, indicador de digitação, avisos de entrada e saída |
| [private-messaging](examples/private-messaging) | mensagens privadas para todas as abas de um usuário com uma sala por usuário, sessão mantida entre reconexões, e quem está online |
| [rooms](examples/rooms) | `join`, `leave`, mensagens para uma sala, lista de membros ao vivo |
| [acks](examples/acks) | acknowledgements nos dois sentidos, e o `timeout()` do servidor |
| [namespaces](examples/namespaces) | `/public` e `/admin`, um middleware com token, `ConnectionError` |
| [binary](examples/binary) | upload e download de arquivo com `Binary` |
| [dashboard](examples/dashboard) | dashboards ao vivo: um timer no servidor manda números novos a cada poucos segundos para quem vê cada um |
| [multi-process](examples/multi-process) | quatro processos e `ChannelAdapter` |
| [redis](examples/redis) | quatro processos e `RedisAdapter`; precisa de um servidor Redis |

## Documentação

- [Configuração](docs/configuration.pt-BR.md): opções do servidor, CORS e cookies, recusar conexões, TLS.
- [Referência da API](docs/api.pt-BR.md): `SocketIO`, namespaces, sockets, acknowledgements, broadcast, dados JSON e binários.
- [Vários processos](docs/multiple-processes.pt-BR.md): `ChannelAdapter` e `RedisAdapter`.
- [Migrando do phpsocket.io](docs/migrating-from-phpsocket.io.pt-BR.md): como mover uma aplicação e os seus clientes.
- [Desenvolvimento](docs/development.pt-BR.md): testes, linters e as suítes de conformidade, tudo no Docker.
- [Changelog](CHANGELOG.md), em inglês.

## Licença

MIT
