# Referência da API

[English](api.md) · **Português (Brasil)**

## `SocketIO`

| | |
|---|---|
| `new SocketIO(?int $port = null, array $options = [])` | Com uma porta, escuta em `tcp://0.0.0.0:<porta>`. Sem porta, chame `attach()`. |
| `attach(Worker $worker)` | Serve o servidor num worker seu, `tcp` ou `ssl`, para outro endereço, vários processos ou TLS. Chame antes de `Worker::runAll()`. |
| `$io->worker` | O worker ligado, ou `null`. |
| `of(string $name): Nsp` | O namespace com esse nome, criado no primeiro uso. |
| `$io->sockets` | O namespace principal, `/`. |
| `on('connection', fn (Socket $socket))` | O evento `connection` do namespace principal; qualquer outro evento, menos `workerStart` e `workerStop`, também é do namespace principal, como os do `serverSideEmit()`. |
| `on('workerStart' \| 'workerStop', fn (Worker $worker))` | Cada processo iniciando ou parando. |
| `use(fn (Socket $socket, Closure $next))` | Um middleware em `/`. |
| `adapter(fn (Nsp $nsp): Adapter)` | Como os namespaces guardam as salas e fazem broadcast, por exemplo `ChannelAdapter::factory()` ou `RedisAdapter::factory()`. |
| `close()` | Desliga o servidor: cada socket sai com `server shutting down`, as conexões fecham, e o worker para de aceitar novas neste processo. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()`, `timeout()` | Broadcast em `/`, como num namespace. |
| `serverSideEmit(string $event, ...$args)` | Um evento para `/` nos outros servidores; veja `Nsp`. |

## Namespaces: `Nsp`

| | |
|---|---|
| `on('connection', fn (Socket $socket))` | Um socket entrou, depois que os middlewares o deixaram passar. `connect` é o mesmo. |
| `use(fn (Socket $socket, Closure $next))` | Roda antes de cada conexão, em ordem. Chame `$next()` para deixar o socket entrar, ou `$next($error)` para recusá-lo; `$next` pode ser chamado depois, de um callback assíncrono. Um `ConnectionError` também envia o seu `data`. Uma exceção lançada antes do `$next` recusa o socket. |
| `emit()`, `send()`, `to()`, `in()`, `except()`, `volatile`, `compress()`, `timeout()` | Broadcast para o namespace. Veja [Acknowledgements](#acknowledgements) para o `timeout()`. |
| `sockets(): array<string, Socket>` | Os sockets conectados, por id. |
| `allSockets(): list<string>` | Os ids deles. |
| `fetchSockets(): list<Socket>`, `socketsJoin()`, `socketsLeave()`, `disconnectSockets(bool $close = false)` | Agem nos sockets selecionados; use `to()` e `except()` antes para selecionar. Com `ChannelAdapter` ou `RedisAdapter`, `socketsJoin()`, `socketsLeave()` e `disconnectSockets()` chegam a todos os processos; `fetchSockets()` lê este. |
| `fetchSockets(fn (?Throwable $error, array $sockets))` | Os sockets selecionados de todos os servidores: objetos `Socket` deste processo, um [`RemoteSocket`](#remotesocket) para cada um em outro lugar. O erro é um `TimeoutException`, com os sockets que já chegaram, quando algum servidor não respondeu em 5 segundos. |
| `serverSideEmit(string $event, ...$args)` | Envia um evento para este namespace nos outros servidores, cujos listeners `on($event)` o recebem. Um `Closure` no fim recebe `(?Throwable $error, array $responses)`, uma resposta por servidor; o erro é um `TimeoutException` depois de 5 segundos. Precisa do `ChannelAdapter` ou do `RedisAdapter`; `connect`, `connection` e `new_namespace` são reservados. Veja [Vários processos](multiple-processes.pt-BR.md#o-que-atravessa-processos). |

## `Socket`

| | |
|---|---|
| `$socket->id` | 24 caracteres hexadecimais, diferente do id da sessão Engine.IO e próprio de cada namespace. |
| `$socket->handshake` | `headers` (nomes em minúsculas), `time`, `address`, `xdomain`, `secure`, `issued` (ms), `url`, `query`, e `auth`, a opção `auth` do cliente. |
| `$socket->data` | Um array livre para os seus dados. |
| `$socket->rooms`, `$socket->connected`, `$socket->disconnected` | As salas dele (incluindo o próprio id) e o estado. |
| `$socket->nsp`, `$socket->request`, `$socket->conn` | O namespace, o request HTTP que abriu a conexão e a sessão Engine.IO. |
| `on(string $event, Closure $listener)` | Um evento do cliente. O listener recebe os argumentos, mais um `Closure` de acknowledgement no fim quando o cliente pediu um; ele responde uma vez só. |
| `emit(string $event, ...$args)`, `send(...$args)` | Envia um evento; um `Closure` como último argumento pede ao cliente que o confirme. |
| `onAny(Closure $listener)`, `prependAny()`, `offAny(?Closure $listener = null)`, `listenersAny()` | Todo evento do cliente, antes dos listeners do próprio evento. O listener recebe o nome do evento, depois os argumentos, com o `Closure` de acknowledgement no fim quando o cliente pediu um. `offAny()` sem listener remove todos. |
| `onAnyOutgoing(Closure $listener)`, `prependAnyOutgoing()`, `offAnyOutgoing()`, `listenersAnyOutgoing()` | Todo evento enviado a este cliente, por `emit()` ou por um broadcast (inclusive de outros processos), antes de ser escrito: o nome do evento, depois os argumentos, sem o callback de acknowledgement. |
| `timeout(int $ms)`, `volatile`, `compress(bool)` | Modificadores do emit, que devolvem um objeto novo. Veja [Acknowledgements](#acknowledgements). |
| `join(string\|array $rooms)`, `leave(string $room)` | Salas. |
| `to()`, `in()`, `except()`, `broadcast` | Broadcast para outros sockets; o próprio socket fica de fora. |
| `disconnect(bool $close = false)` | Sai do namespace; com `true`, fecha a conexão inteira. |

Eventos: `disconnecting` e `disconnect` recebem `(string $reason, ?Throwable $description)`; `disconnecting` ainda vê as salas. Os motivos são `transport close`, `transport error`, `ping timeout`, `parse error`, `forced close`, `server namespace disconnect`, `client namespace disconnect`, `forced server close` e `server shutting down`. `error` recebe um `Throwable` quando o cliente enviou um pacote que quebra o protocolo, logo antes de a conexão fechar.

Os nomes `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` e `removeListener` são reservados: emiti-los lança exceção.

## `RemoteSocket`

Um socket em outro servidor, vindo do `fetchSockets()` com callback:

```php
$io->in('room1')->fetchSockets(function (?Throwable $error, array $sockets): void {
    foreach ($sockets as $socket) {   // Socket here, RemoteSocket on another server
        $socket->emit('hello', $socket->data['name'] ?? null);
    }
});
```

| | |
|---|---|
| `id`, `handshake`, `rooms`, `data` | Cópias, como o servidor do socket os tinha. `handshake` e `data` atravessam como JSON, então objetos chegam como arrays. |
| `emit(string $event, ...$args)` | Envia um evento para ele. |
| `timeout(int $ms)` | Deixa o `emit()` receber um callback, que recebe `(?Throwable $error, mixed $answer)` como no `Socket::timeout()`. Devolve um objeto novo. |
| `join(string\|array $rooms)`, `leave(string $room)` | As salas dele, no servidor dele. |
| `disconnect(bool $close = false)` | Desconecta-o do namespace; com `true`, fecha a conexão dele. |

## Acknowledgements

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

Um broadcast aceita um callback de acknowledgement depois de `timeout()`. O callback recebe uma resposta por socket alcançado, na ordem em que chegam: o primeiro argumento do acknowledgement de cada cliente.

```php
$io->to('room1')->timeout(5000)->emit('vote', function (?Throwable $error, array $answers): void {
    // $error is null once every socket answered,
    // or a TimeoutException, with the answers that arrived in time.
});
```

- Sem `timeout()`, passar um `Closure` lança exceção, já que um socket que nunca responde deixaria o callback esperando para sempre.
- Sem nenhum socket a alcançar, o callback roda na hora com `[]`.
- Com `volatile`, um socket pulado por ter o transporte ocupado continua contando, então a espera termina no timeout.
- Com o `ChannelAdapter` ou o `RedisAdapter`, alcança os sockets de todos os processos: o callback roda quando cada servidor informou quantos sockets alcançou e todos eles responderam.

## Broadcast: `BroadcastOperator`

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

## Erros

- Um middleware recusa uma conexão com `$next(new ConnectionError($message, $data))`: o `connect_error` do cliente recebe `message` e `data`.
- Um pacote do cliente que quebra o protocolo fecha a conexão dele.
- Uma exceção dos seus listeners fecha a conexão de onde veio e é registrada com `Worker::log`; o worker continua rodando.

## Dados: JSON e binário

- Objetos JSON chegam como arrays associativos do PHP. O PHP não distingue `{}` de `[]`, então envie um objeto vazio como `new stdClass()`.
- Dados binários chegam como `Marcolin\SocketIO\Binary`, em qualquer ponto dos dados. Envolva os bytes em `new Binary($bytes)` para enviá-los; o cliente recebe um `Buffer` ou um `ArrayBuffer`.
- Strings precisam ser UTF-8 válido; para outros bytes, use `Binary`.
