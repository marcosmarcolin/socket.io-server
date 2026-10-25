# socket.io-server

[![CI](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marcosmarcolin/socket.io-server/actions/workflows/ci.yml)

[English](README.md) · **Português (Brasil)**

Um servidor [Socket.IO](https://socket.io) v4 para PHP, construído sobre o [Workerman](https://github.com/walkor/workerman).

Ele fala os protocolos atuais, Engine.IO v4 e Socket.IO v5, então navegadores e apps usam o `socket.io-client` oficial v3 ou v4. Ele passa nas suítes oficiais de conformidade do Engine.IO e do Socket.IO, e o CI o testa contra o cliente oficial.

> **Em desenvolvimento.** Ainda não foi publicado no Packagist, e a API ainda pode mudar antes da primeira release.

## Sumário

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Início rápido](#início-rápido)
- [O lado do cliente](#o-lado-do-cliente)
- [Opções do servidor](#opções-do-servidor)
  - [Cookies entre origens](#cookies-entre-origens)
- [API](#api)
- [Dados: JSON e binário](#dados-json-e-binário)
- [TLS](#tls)
- [Vários processos](#vários-processos)
- [Exemplos](#exemplos)
- [Migrando do phpsocket.io](#migrando-do-phpsocketio)
- [Situação](#situação)
- [Desenvolvimento](#desenvolvimento)
- [Licença](#licença)

## Requisitos

- PHP 8.1 ou mais recente, com a extensão `json`, e `pcntl` e `posix` para rodar o Workerman.
- [Workerman](https://github.com/walkor/workerman) 5.2.
- No cliente: `socket.io-client` v3 ou v4. Para os clientes 1.x e 2.x, use o [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io).

Fora do escopo: WebTransport, polling JSONP, parsers customizados (msgpack), namespaces dinâmicos, recuperação de estado de conexão e um adapter Redis.

## Instalação

O pacote ainda não está no Packagist. Até a primeira release, instale a partir do repositório Git:

```json
{
    "repositories": [{"type": "vcs", "url": "https://github.com/marcosmarcolin/socket.io-server"}],
    "require": {"marcosmarcolin/socket.io-server": "dev-main"}
}
```

## Início rápido

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

Rode com `php server.php start` (`start -d` para rodar em segundo plano, `stop`, `restart`, `status`: os comandos de sempre do Workerman).

## O lado do cliente

```js
import { io } from "socket.io-client";

const socket = io("http://localhost:3000", { auth: { token: "secret" } });

socket.on("hello", (word) => console.log(word));
socket.emit("chat", "hi", (status) => console.log(status)); // "delivered"
socket.on("question", (answer) => answer("blue")); // the server's timeout() emit
```

Com vários processos do servidor na mesma porta, acrescente `transports: ["websocket"]` (veja [Vários processos](#vários-processos)).

## Opções do servidor

`new SocketIO($port, $options)` aceita estas opções. Tempos em milissegundos.

| Opção | Padrão | Significado |
|---|---|---|
| `pingInterval` | `25000` | De quanto em quanto tempo o servidor manda ping para cada cliente. |
| `pingTimeout` | `20000` | Quanto ele espera o pong antes de fechar a conexão. |
| `maxPayload` | `1000000` | Maior mensagem, em bytes; corpos HTTP maiores recebem 413. |
| `upgradeTimeout` | `10000` | Quanto tempo um upgrade de polling para WebSocket pode levar. |
| `connectTimeout` | `45000` | Quanto tempo um cliente pode ficar sem entrar em um namespace. |
| `path` | `'/socket.io/'` | O caminho da URL; a opção `path` do cliente precisa ser igual. |
| `transports` | `['polling', 'websocket']` | Os transportes que os clientes podem usar. Com `['websocket']`, requests de polling são recusados, então os clientes também precisam de `transports: ['websocket']` (senão recebem `xhr poll error`). Com `['polling']`, nenhum upgrade é oferecido. |
| `cors` | `['origin' => '*']` | `origin` é `'*'`, uma lista de origens permitidas, um `Closure` que decide a cada request, ou `null` para não enviar cabeçalhos CORS. `credentials` (`false` por padrão) deixa o navegador enviar cookies. |

Opções desconhecidas e tipos errados lançam `InvalidArgumentException`.

### Cookies entre origens

Quando a página e o servidor diferem no host ou na porta, o navegador só envia cookies se o cliente pedir e o servidor permitir. Um caso típico é autenticar por cookie de sessão:

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

Com `credentials`, `origin` não pode ser `'*'`: o navegador recusa esse par, então isso lança exceção.

O CORS só cobre o HTTP long-polling: o navegador não o aplica ao WebSocket, então uma página de outro site pode abrir um WebSocket para o seu servidor, com os seus cookies se forem `SameSite=None`. Para recusar outras origens em qualquer transporte, verifique o cabeçalho `Origin` também num middleware:

```php
$io->use(function (Socket $socket, Closure $next): void {
    $origin = $socket->handshake['headers']['origin'] ?? '';
    $host = $socket->handshake['headers']['host'] ?? '';
    $sameHost = parse_url($origin, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST);
    $next($sameHost ? null : new ConnectionError('forbidden origin'));
});
```

## API

### `SocketIO`

| | |
|---|---|
| `new SocketIO(?int $port = null, array $options = [])` | Com uma porta, escuta em `tcp://0.0.0.0:<porta>`. Sem porta, chame `attach()`. |
| `attach(Worker $worker)` | Serve o servidor num worker seu, `tcp` ou `ssl`, para outro endereço, vários processos ou TLS. Chame antes de `Worker::runAll()`. |
| `$io->worker` | O worker ligado, ou `null`. |
| `of(string $name): Nsp` | O namespace com esse nome, criado no primeiro uso. |
| `$io->sockets` | O namespace principal, `/`. |
| `on('connection', fn (Socket $socket))` | O evento `connection` do namespace principal. |
| `on('workerStart' \| 'workerStop', fn (Worker $worker))` | Cada processo iniciando ou parando. |
| `use(fn (Socket $socket, Closure $next))` | Um middleware em `/`. |
| `adapter(fn (Nsp $nsp): Adapter)` | Como os namespaces guardam as salas e fazem broadcast, por exemplo `ChannelAdapter::factory()`. |
| `close()` | Desliga o servidor: cada socket sai com `server shutting down`, as conexões fecham, e o worker para de aceitar novas neste processo. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()` | Broadcast em `/`, como num namespace. |

### Namespaces: `Nsp`

| | |
|---|---|
| `on('connection', fn (Socket $socket))` | Um socket entrou, depois que os middlewares o deixaram passar. `connect` é o mesmo. |
| `use(fn (Socket $socket, Closure $next))` | Roda antes de cada conexão, em ordem. Chame `$next()` para deixar o socket entrar, ou `$next($error)` para recusá-lo; `$next` pode ser chamado depois, de um callback assíncrono. Um `ConnectionError` também envia o seu `data`. Uma exceção lançada antes do `$next` recusa o socket. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()` | Broadcast para o namespace. |
| `sockets(): array<string, Socket>` | Os sockets conectados, por id. |
| `allSockets(): list<string>` | Os ids deles. |
| `fetchSockets(): list<Socket>`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets(bool $close = false)` | Agem nos sockets selecionados; use `to()` e `except()` antes para selecionar. |

### `Socket`

| | |
|---|---|
| `$socket->id` | 24 caracteres hexadecimais, diferente do id da sessão Engine.IO e próprio de cada namespace. |
| `$socket->handshake` | `headers` (nomes em minúsculas), `time`, `address`, `xdomain`, `secure`, `issued` (ms), `url`, `query`, e `auth`, a opção `auth` do cliente. |
| `$socket->data` | Um array livre para os seus dados. |
| `$socket->rooms`, `$socket->connected`, `$socket->disconnected` | As salas dele (incluindo o próprio id) e o estado. |
| `$socket->nsp`, `$socket->request`, `$socket->conn` | O namespace, o request HTTP que abriu a conexão e a sessão Engine.IO. |
| `on(string $event, Closure $listener)` | Um evento do cliente. O listener recebe os argumentos, mais um `Closure` de acknowledgement no fim quando o cliente pediu um; ele responde uma vez só. |
| `emit(string $event, ...$args)`, `send(...$args)` | Envia um evento; um `Closure` como último argumento pede ao cliente que o confirme. |
| `timeout(int $ms)`, `volatile`, `compress(bool)` | Modificadores do emit, que devolvem um objeto novo. Veja [Acknowledgements](#acknowledgements). |
| `join(string\|array $rooms)`, `leave(string $room)` | Salas. |
| `to()`, `in()`, `except()`, `broadcast` | Broadcast para outros sockets; o próprio socket fica de fora. |
| `disconnect(bool $close = false)` | Sai do namespace; com `true`, fecha a conexão inteira. |

Eventos: `disconnecting` e `disconnect` recebem `(string $reason, ?Throwable $description)`; `disconnecting` ainda vê as salas. Os motivos são `transport close`, `transport error`, `ping timeout`, `parse error`, `forced close`, `server namespace disconnect`, `client namespace disconnect`, `forced server close` e `server shutting down`. `error` recebe um `Throwable` quando o cliente enviou um pacote que quebra o protocolo, logo antes de a conexão fechar.

Os nomes `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` e `removeListener` são reservados: emiti-los lança exceção.

### Acknowledgements

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

Broadcasts não aceitam acknowledgements: passar um `Closure` lança exceção.

### Broadcast: `BroadcastOperator`

`to()`, `in()`, `except()` e `volatile` devolvem um operador imutável:

```php
$io->emit('news', $data);                         // every socket of "/"
$io->to('room1')->to('room2')->emit('news');      // sockets in either room, once each
$io->except('muted')->emit('news');               // everyone but the sockets in "muted"
$io->of('/admin')->to($socketId)->emit('ping');   // one socket, by its id
$socket->broadcast->emit('joined', $socket->id);  // everyone but this socket
$socket->to('room1')->emit('typing');             // the room, but not this socket
$io->volatile->emit('position', $x, $y);          // skipped where the transport is busy
```

`compress()` existe por compatibilidade e não tem efeito: o servidor não comprime.

### Erros

- Um middleware recusa uma conexão com `$next(new ConnectionError($message, $data))`: o `connect_error` do cliente recebe `message` e `data`.
- Um pacote do cliente que quebra o protocolo fecha a conexão dele.
- Uma exceção dos seus listeners fecha a conexão de onde veio e é registrada com `Worker::log`; o worker continua rodando.

## Dados: JSON e binário

- Objetos JSON chegam como arrays associativos do PHP. O PHP não distingue `{}` de `[]`, então envie um objeto vazio como `new stdClass()`.
- Dados binários chegam como `Marcolin\SocketIO\Binary`, em qualquer ponto dos dados. Envolva os bytes em `new Binary($bytes)` para enviá-los; o cliente recebe um `Buffer` ou um `ArrayBuffer`.
- Strings precisam ser UTF-8 válido; para outros bytes, use `Binary`.

## TLS

Crie o worker com o certificado, marque-o como `ssl` e ligue-o:

```php
$worker = new Worker('tcp://0.0.0.0:443', [
    'ssl' => ['local_cert' => '/path/to/cert.pem', 'local_pk' => '/path/to/key.pem', 'verify_peer' => false],
]);
$worker->transport = 'ssl';
$io->attach($worker);
```

## Vários processos

O broadcast chega aos outros processos pelo `ChannelAdapter` e por um servidor [workerman/channel](https://github.com/walkor/channel):

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

- Os clientes precisam conectar com `transports: ['websocket']`: o polling exige que todos os requests de uma sessão cheguem ao mesmo processo, o que vários processos na mesma porta não garantem. Definir `'transports' => ['websocket']` também no servidor recusa o polling de vez.
- Só o broadcast atravessa processos (`emit`, `to`, `except`, `broadcast`). `fetchSockets`, `allSockets`, `socketsJoin`, `socketsLeave` e `disconnectSockets` agem no processo atual.

## Exemplos

Cada um roda com `php examples/<nome>/start.php start`; depois abra http://localhost:3001.

| Exemplo | Mostra |
|---|---|
| [chat](examples/chat) | uma sala de chat: nomes, indicador de digitação, avisos de entrada e saída |
| [rooms](examples/rooms) | `join`, `leave`, mensagens para uma sala, lista de membros ao vivo |
| [acks](examples/acks) | acknowledgements nos dois sentidos, e o `timeout()` do servidor |
| [namespaces](examples/namespaces) | `/public` e `/admin`, um middleware com token, `ConnectionError` |
| [binary](examples/binary) | upload e download de arquivo com `Binary` |
| [multi-process](examples/multi-process) | quatro processos e `ChannelAdapter` |

## Migrando do phpsocket.io

Vindo do [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io): veja [o guia de migração](docs/migrating-from-phpsocket.io.pt-BR.md). Os seus clientes passam para o `socket.io-client` v3 ou v4.

## Situação

As mudanças estão listadas no [CHANGELOG.md](CHANGELOG.md), em inglês.

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
| Testes de integração com vários processos e verificação de escala (200 clientes, 4.000 entregas) | Pronto |
| Exemplos: chat, salas, acknowledgements, namespaces, dados binários, vários processos | Pronto |
| README completo com referência da API, e guia de migração do phpsocket.io | Pronto |
| Primeira release | Próxima |

## Desenvolvimento

Tudo roda no Docker; não é preciso ter PHP nem Composer na máquina.

```sh
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php composer lint      # phpcs (PSR-12) e PHPStan (nível max)
tests/Compliance/engine-io.sh                  # a suíte oficial do Engine.IO v4
tests/Compliance/socket-io.sh                  # a suíte oficial do Socket.IO v5
tests/Integration/run.sh                       # o socket.io-client oficial contra este servidor
tests/Integration/run.sh multi-process         # o mesmo com dois processos e um servidor de canal
tests/Integration/run.sh examples              # todos os exemplos, cada um contra o seu teste
```

### Varredura de segredos

O [gitleaks](https://github.com/gitleaks/gitleaks) roda no Docker e bloqueia commits que adicionam segredos. Ative o hook uma vez por clone:

```sh
git config core.hooksPath .githooks
```

O Docker precisa estar rodando para commitar. O CI também varre o histórico inteiro em todo pull request.

## Licença

MIT
