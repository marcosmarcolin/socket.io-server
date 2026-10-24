# socket.io-server

[English](README.md) · **Português (Brasil)**

Um servidor [Socket.IO](https://socket.io) v4 para PHP, construído sobre o [Workerman](https://github.com/walkor/workerman).

> **Em desenvolvimento.** O servidor funciona e passa nas suítes oficiais de conformidade do Engine.IO e do Socket.IO, mas ainda não foi publicado no Packagist e a API ainda pode mudar.

## Escopo

- Implementa os protocolos Engine.IO v4 e Socket.IO v5, para o `socket.io-client` v3 e v4.
- Transportes: HTTP long-polling e WebSocket, com o upgrade do polling para WebSocket.
- PHP >= 8.1 e Workerman 5. Não exige framework.

Para o `socket.io-client` v1 e v2, use o [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

## Situação

| Etapa | Estado |
|---|---|
| Parser de pacotes Engine.IO | Pronto |
| Sessão Engine.IO: heartbeat, buffer de escrita, transportes polling e WebSocket, upgrade | Pronto |
| Servidor Engine.IO: validação de requests, sessões, CORS | Pronto |
| Integração com o Workerman: uma porta para HTTP e WebSocket, timers, adaptadores de conexão | Pronto |
| A suíte oficial do Engine.IO v4 rodando no CI (25 de 25 passando) | Pronto |
| Parser Socket.IO v5: encoder, decoder, anexos binários | Pronto |
| Servidor Socket.IO: conexão, namespaces, middlewares, eventos, acks, salas | Pronto |
| Broadcast para salas e namespaces | Pronto |
| A suíte oficial do Socket.IO v5 rodando no CI (32 de 32 passando) | Pronto |
| Testes de integração com o `socket.io-client` 4.8.4 oficial: polling, WebSocket, upgrade, namespaces, salas, acks, binário | Pronto |
| Broadcast entre processos: `ChannelAdapter` sobre o `workerman/channel` | Pronto |
| Testes de integração com vários processos e verificação de escala | Próxima |

## Uso

```php
<?php

use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(3000, [
    'pingInterval' => 25000,
    'pingTimeout' => 20000,
    'cors' => ['origin' => ['https://example.com']],
]);

// Runs before each connection to "/".
$io->use(function (Socket $socket, Closure $next): void {
    $token = $socket->handshake['auth']['token'] ?? null;
    $next($token === 'secret' ? null : new ConnectionError('unauthorized'));
});

$io->on('connection', function (Socket $socket) use ($io): void {
    $socket->data['joinedAt'] = time();
    $socket->join('lobby');
    $socket->emit('hello', 'world');
    $socket->broadcast->emit('joined', $socket->id);

    // A Closure as the last argument is the client's acknowledgement callback.
    $socket->on('chat', function (string $message, ?Closure $ack = null) use ($socket): void {
        $socket->to('lobby')->emit('chat', $message);
        if ($ack !== null) {
            $ack('delivered');
        }
    });

    $socket->timeout(5000)->emit('question', function (?Throwable $error, mixed $answer): void {
        // $error is a TimeoutException when the client didn't answer in time.
    });

    $socket->on('disconnect', function (string $reason) use ($io, $socket): void {
        $io->to('lobby')->emit('left', $socket->id, $reason);
    });
});

$io->of('/admin')->on('connection', function (Socket $socket): void {
    $socket->emit('welcome', ['role' => 'admin']);
});

Worker::runAll();
```

Rode com `php server.php start`. Para escolher o endereço, o número de processos ou TLS, crie o `Worker` você mesmo e chame `$io->attach($worker)` antes de `Worker::runAll()`.

Objetos JSON chegam como arrays PHP. Para enviar um objeto vazio, use `new stdClass()`. Para enviar dados binários, envolva-os em `Marcolin\SocketIO\Binary`.

Por enquanto, rode um único processo: o broadcast entre processos chega com o `ChannelAdapter`. Com vários processos na mesma porta, os clientes também vão precisar de `transports: ['websocket']`, porque o polling exige sticky sessions.

## Desenvolvimento

Tudo roda no Docker; não é preciso ter PHP nem Composer na máquina.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) e PHPStan (nível max)
tests/Compliance/engine-io.sh                  # a suíte oficial do Engine.IO v4
tests/Compliance/socket-io.sh                  # a suíte oficial do Socket.IO v5
tests/Integration/run.sh                       # o socket.io-client oficial contra este servidor
```

### Varredura de segredos

O [gitleaks](https://github.com/gitleaks/gitleaks) roda no Docker e bloqueia commits que adicionam segredos. Ative o hook uma vez por clone:

```sh
git config core.hooksPath .githooks
```

O Docker precisa estar rodando para commitar. O CI também varre o histórico inteiro em todo pull request.

## Licença

MIT
