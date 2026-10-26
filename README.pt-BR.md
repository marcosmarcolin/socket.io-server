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
- Um `Emitter` que envia para os clientes a partir de qualquer código PHP, como um request do PHP-FPM.
- CORS com cookies, um gancho para recusar conexões antes do handshake, e TLS.
- Compressão das respostas de polling e das mensagens WebSocket, e um log de depuração.
- Cerca de 30 KB por conexão: 10.000 clientes WebSocket num processo ocupam 300 MB (veja [Desempenho e limites](docs/performance.pt-BR.md)).
- Uma API no formato da do [phpsocket.io](https://github.com/walkor/phpsocket.io), para facilitar a migração.

## Compatibilidade

| | |
|---|---|
| Protocolos | Engine.IO v4 e Socket.IO v5 |
| Clientes | `socket.io-client` v3 e v4; o CI roda o 4.8.4 em HTTP long-polling, WebSocket e no upgrade. Outros clientes desses protocolos, como os oficiais de Java e Swift, devem funcionar, mas não são testados aqui. |
| Conformidade | as suítes oficiais de teste do [Engine.IO](https://github.com/socketio/socket.io/tree/main/docs/engine.io-protocol/v4-test-suite) (25 de 25) e do [Socket.IO](https://github.com/socketio/socket.io/tree/main/docs/socket.io-protocol/v5-test-suite) (32 de 32), em todo pull request |
| Não suportado | `socket.io-client` 1.x e 2.x (Engine.IO v3): use o [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io) |

## Requisitos

- PHP 8.1 ou mais recente, com a extensão `json`, e `pcntl` e `posix` para rodar o Workerman.
- [Workerman](https://github.com/walkor/workerman) 5.2, e a extensão `event` para mais de cerca de 1.000 conexões por processo (veja [Desempenho e limites](docs/performance.pt-BR.md)).
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
| [session-auth](examples/session-auth) | login com sessão PHP, e um middleware que só deixa entrar os sockets de sessões logadas |
| [private-messaging](examples/private-messaging) | mensagens privadas para todas as abas de um usuário com uma sala por usuário, sessão mantida entre reconexões, e quem está online |
| [rooms](examples/rooms) | `join`, `leave`, mensagens para uma sala, lista de membros ao vivo |
| [acks](examples/acks) | acknowledgements nos dois sentidos, e o `timeout()` do servidor |
| [namespaces](examples/namespaces) | `/public` e `/admin`, um middleware com token, `ConnectionError` |
| [binary](examples/binary) | upload e download de arquivo com `Binary` |
| [dashboard](examples/dashboard) | dashboards ao vivo: um timer no servidor manda números novos a cada poucos segundos para quem vê cada um |
| [multi-process](examples/multi-process) | quatro processos e `ChannelAdapter` |
| [emitter](examples/emitter) | um endpoint PHP comum enviando notificações aos clientes com o `Emitter`; precisa de um servidor Redis |
| [redis](examples/redis) | quatro processos e `RedisAdapter`; precisa de um servidor Redis |

## Documentação

- [Configuração](docs/configuration.pt-BR.md): opções do servidor, CORS e cookies, recusar conexões, TLS.
- [Referência da API](docs/api.pt-BR.md): `SocketIO`, namespaces, sockets, acknowledgements, broadcast, dados JSON e binários.
- [Vários processos](docs/multiple-processes.pt-BR.md): `ChannelAdapter`, `RedisAdapter` e o `Emitter`.
- [Atrás de um proxy reverso](docs/reverse-proxy.pt-BR.md): nginx e HAProxy, TLS e sticky sessions para o polling.
- [Desempenho e limites](docs/performance.pt-BR.md): quanto um processo aguenta, a extensão `event`, e o teste de carga.
- [Migrando do phpsocket.io](docs/migrating-from-phpsocket.io.pt-BR.md): como mover uma aplicação e os seus clientes.
- [Desenvolvimento](docs/development.pt-BR.md): testes, linters e as suítes de conformidade, tudo no Docker.
- [Changelog](CHANGELOG.md), em inglês.

## Licença

MIT
