# Configuração

[English](configuration.md) · **Português (Brasil)**

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
| `httpCompression` | `true` | Comprime as respostas de polling de 1 KB ou mais com gzip ou deflate, conforme o `Accept-Encoding` do cliente, como na referência. `false` desliga; `['threshold' => bytes]` muda o tamanho. Uma resposta cujos pacotes foram todos enviados com `compress(false)` vai sem compressão. |
| `perMessageDeflate` | `false` | Com `true`, as mensagens WebSocket de 1 KB ou mais vão comprimidas (permessage-deflate) quando o cliente oferece, como a opção da referência; `['threshold' => bytes]` muda o tamanho. Desligada por padrão, como na referência: custa CPU e memória por conexão. |
| `cors` | `['origin' => '*']` | `origin` é `'*'`, uma lista de origens permitidas, um `Closure` que decide a cada request, ou `null` para não enviar cabeçalhos CORS. `credentials` (`false` por padrão) deixa o navegador enviar cookies. |
| `allowUpgrades` | `true` | Com `false`, o handshake não oferece upgrade, então clientes que começam no polling continuam nele; clientes com `transports: ['websocket']` continuam conectando por WebSocket. |
| `cookie` | `false` | Com `true`, o handshake de polling grava o cookie `io=<id da sessão>` (`Path=/; HttpOnly; SameSite=Lax`), que um load balancer pode usar para sticky sessions; veja [Atrás de um proxy reverso](reverse-proxy.pt-BR.md). Um array muda os atributos: `name`, `path`, `domain`, `httpOnly`, `secure`, `sameSite`, `maxAge` (segundos). |
| `allowRequest` | `null` | Um `Closure` que aceita ou recusa cada handshake antes de a sessão abrir. Veja [Recusar uma conexão antes do handshake](#recusar-uma-conexão-antes-do-handshake). |

Opções desconhecidas e tipos errados lançam `InvalidArgumentException`.

## Cookies entre origens

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

O [exemplo session-auth](../examples/session-auth) junta tudo: uma página em outra porta faz login com sessão PHP, e um middleware só deixa entrar os sockets de sessões logadas.

O CORS só cobre o HTTP long-polling: o navegador não o aplica ao WebSocket, então uma página de outro site pode abrir um WebSocket para o seu servidor, com os seus cookies se forem `SameSite=None`. Para recusar outras origens em qualquer transporte, verifique o cabeçalho `Origin` também num middleware:

```php
$io->use(function (Socket $socket, Closure $next): void {
    $origin = $socket->handshake['headers']['origin'] ?? '';
    $host = $socket->handshake['headers']['host'] ?? '';
    $sameHost = parse_url($origin, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST);
    $next($sameHost ? null : new ConnectionError('forbidden origin'));
});
```

## Recusar uma conexão antes do handshake

`allowRequest` recebe o `Engine\Request` de cada handshake, por polling ou WebSocket, antes de qualquer sessão abrir. Ele retorna `true` para aceitar. `false` ou uma mensagem recusa com o código 4 (`Forbidden`): HTTP 403 com `{"code":4,"message":"..."}` no polling, HTTP 400 com a mensagem no WebSocket. O cliente recebe `connect_error`.

```php
use Marcolin\SocketIO\Engine\Request;

$io = new SocketIO(3000, [
    'allowRequest' => function (Request $request) use ($sessions): bool|string {
        return $sessions->isValid($request->header('cookie') ?? '') ? true : 'invalid session';
    },
]);
```

Ele roda de forma síncrona no event loop, então mantenha-o rápido: uma verificação lenta segura todas as conexões do processo. Uma exceção fecha a conexão (com um 500 no polling) e vai para o log do worker.

Um middleware faz o mesmo papel um passo depois, com a sessão já aberta: ele pode esperar trabalho assíncrono, e o seu `ConnectionError` chega ao cliente com mensagem e dados em qualquer transporte. Com `allowRequest`, o cliente só lê a mensagem no polling, em `err.context.responseText`; no WebSocket ele vê `websocket error`.

## Eventos do engine

`$io->engine` emite eventos sobre o lado HTTP de cada conexão, como o `io.engine` da referência:

```php
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\ResponseHeaders;

// The first answer of each session: the polling handshake, or the 101 of a WebSocket.
$io->engine->on('initial_headers', function (ResponseHeaders $headers, Request $request): void {
    $headers->append('Set-Cookie', 'lb=web-1; Path=/; HttpOnly');
});

// Every successful polling answer and every 101.
$io->engine->on('headers', function (ResponseHeaders $headers, Request $request): void {
    $headers->set('X-Served-By', gethostname());
});

// A refused request, such as an unknown session or a refusal from allowRequest.
$io->engine->on('connection_error', function (Request $request, int $code, string $message): void {
    Worker::log("refused {$request->uri}: $code $message");
});
```

- `ResponseHeaders` tem `set()`, `append()` (uma linha por valor, como vários `Set-Cookie` precisam), `get()`, `remove()` e `all()`; os nomes não diferenciam maiúsculas, e um nome que não é um token de header ou um valor com quebra de linha lança exceção.
- As respostas de erro e o preflight de CORS saem sem o evento `headers`.
- O `code` do `connection_error` é o código de erro do Engine.IO (0 a 5) da resposta, e `message` a sua mensagem: a que o `allowRequest` devolveu, quando ele recusou.

## TLS

Crie o worker com o certificado, marque-o como `ssl` e ligue-o:

```php
$worker = new Worker('tcp://0.0.0.0:443', [
    'ssl' => ['local_cert' => '/path/to/cert.pem', 'local_pk' => '/path/to/key.pem', 'verify_peer' => false],
]);
$worker->transport = 'ssl';
$io->attach($worker);
```
