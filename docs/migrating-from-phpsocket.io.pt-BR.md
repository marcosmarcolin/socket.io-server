# Migrando do phpsocket.io

[English](migrating-from-phpsocket.io.md) · **Português (Brasil)**

Para aplicações no [workerman/phpsocket.io](https://github.com/walkor/phpsocket.io) 2.x ou 3.0. A API do lado do servidor mantém o formato do phpsocket.io, então a maior parte do código migra com pequenos ajustes. A mudança de verdade é do lado do cliente.

## 1. Planeje a troca dos clientes

O phpsocket.io atende o `socket.io-client` de 1.3 a 2.x (protocolo Engine.IO v3). Este servidor atende o `socket.io-client` v3 e v4 (Engine.IO v4, Socket.IO v5). Os protocolos são incompatíveis nos dois sentidos: um cliente antigo não conecta aqui, e um cliente novo não conecta no phpsocket.io.

Por isso, clientes e servidor mudam juntos. Um caminho seguro é rodar os dois servidores lado a lado, cada um na sua porta ou caminho, publicar clientes que usam o novo e desligar o phpsocket.io quando não restar nenhum cliente antigo.

Do lado do cliente, siga a [migração do Socket.IO de 2.x para 3.0](https://socket.io/docs/v4/migrating-from-2-x-to-3-0/). Os pontos principais:

- `io()` conecta em `/` explicitamente: não existe mais conexão implícita ao namespace principal.
- As credenciais vão na opção `auth`, e o servidor as lê em `$socket->handshake['auth']`, em vez de irem na query string.
- Com vários processos no servidor, conecte com `transports: ['websocket']`.
- **Cookies**: o cliente 2.x envia cookies entre origens por padrão, e o phpsocket.io aceita qualquer origem com credenciais. O cliente v3/v4 só os envia com `withCredentials: true`, e este servidor precisa de `'cors' => ['origin' => ..., 'credentials' => true]` com uma lista de origens ou um `Closure` (veja [Cookies entre origens](../README.pt-BR.md#cookies-entre-origens)). Sem os dois, um login por cookie para de funcionar sem nenhum erro aparente.

## 2. Requisitos e Composer

| | phpsocket.io | socket.io-server |
|---|---|---|
| PHP | 7.4 ou mais recente | 8.1 ou mais recente |
| Workerman | 4.x | 5.2 |
| Pacote | `workerman/phpsocket.io` | `marcosmarcolin/socket.io-server` |
| Namespace | `PHPSocketIO\` | `Marcolin\SocketIO\` |

O `workerman/channel` continua sendo o jeito de rodar vários processos, e continua opcional.

## 3. O que muda no seu código

| phpsocket.io | socket.io-server |
|---|---|
| `use PHPSocketIO\SocketIO;` | `use Marcolin\SocketIO\SocketIO;` |
| `new SocketIO(2026)` | igual |
| `new SocketIO(2026, ['ssl' => [...]])`: o segundo argumento é o contexto do socket | o segundo argumento traz as [opções do servidor](../README.pt-BR.md#opções-do-servidor). Para TLS, crie o worker com o contexto, defina `$worker->transport = 'ssl'` e chame `$io->attach($worker)` ([TLS](../README.pt-BR.md#tls)) |
| `['host' => '::1']` | `$io->attach(new Worker('tcp://[::1]:2026'))` |
| `['origins' => 'http://a.com:80']`, `$io->origins(...)` | `['cors' => ['origin' => ['http://a.com']]]`, mais `'credentials' => true` para cookies |
| `['nsp' => MyNsp::class, 'socket' => MySocket::class]` | não suportado: as classes são finais. Use middlewares e `$socket->data` |
| `$io->worker->count = 4` | continua funcionando. Acrescente `$io->worker->reusePort = true` para o kernel distribuir as conexões |
| `$io->adapter(ChannelAdapter::class)` com `ChannelAdapter::$ip` e `::$port` | `$io->adapter(ChannelAdapter::factory($ip, $port))`, ainda no `workerStart`, com `use Marcolin\SocketIO\Adapter\ChannelAdapter;` |
| `$socket->username = 'ana'` (propriedades dinâmicas) | `$socket->data['username'] = 'ana'` |
| `function ($data, $callback) { if (is_callable($callback)) {...} }` | o acknowledgement é um `Closure`, sempre o último argumento: `function ($data, ?Closure $ack = null)` |
| `$socket->emit('event', $data, $callback)` com qualquer callable | o callback precisa ser um `Closure` |
| `$socket->emit('disconnect')`, `'connect'`, `'error'`...: roda os listeners locais | `connect`, `connect_error`, `disconnect`, `disconnecting`, `newListener` e `removeListener` lançam exceção; `error` é enviado ao cliente como qualquer evento |
| `$socket->join('room')` | igual, e também aceita uma lista: `$socket->join(['a', 'b'])` |
| `$socket->rooms`: um mapa, `sala => sala` | uma lista: `['<id do socket>', 'sala']` |
| `$socket->to('room')` devolve o socket, com flags até o próximo `emit` | devolve um `BroadcastOperator` novo e imutável. `$socket->to('room')->emit(...)` se lê igual |
| `$socket->handshake`: sem `auth`; `issued` em segundos | ganha `auth`; `issued` é em milissegundos; nomes de cabeçalho em minúsculas |
| `$socket->conn->remoteAddress` | `$socket->handshake['address']` ou `$socket->request->remoteAddress` |
| `$nsp->clients(function ($sids) {...})` | `$nsp->allSockets()` devolve os ids; `$nsp->to('room')->allSockets()` para uma sala |
| `$nsp->sockets` (array) | `$nsp->sockets()` |
| acknowledgements em broadcast lançam exceção | continuam lançando |

## 4. O que há de novo

- **Middlewares**: `$io->use()` e `$io->of('/admin')->use()` rodam antes de cada conexão. Recuse com `$next(new ConnectionError('mensagem', $data))`, e o `connect_error` do cliente recebe os dois.
- **Timeouts**: `$socket->timeout(5000)->emit('question', function (?Throwable $error, $answer) {...})`.
- **`except()`**, `fetchSockets()`, `socketsJoin()`, `socketsLeave()` e `disconnectSockets()` em namespaces e broadcasts.
- **Dados binários**: `Marcolin\SocketIO\Binary`, nos dois sentidos, em qualquer ponto dos dados.
- **`ChannelAdapter`** publica cada broadcast uma vez só, então um socket em várias das salas de destino o recebe uma vez.

## 5. O que deixa de existir

- `socket.io-client` 1.x e 2.x, e o polling JSONP.
- Classes `Nsp` e `Socket` customizadas.
- `PHPSocketIO\Debug`, já removido no phpsocket.io 3.0.
