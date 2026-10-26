<?php

/**
 * Authentication by session cookie: the page logs in with a PHP session, and a middleware lets in only the sockets
 * whose cookie belongs to a logged-in session. In your app, read the session store your PHP-FPM code writes (files,
 * Redis...) in the same middleware.
 *
 *   php examples/session-auth/start.php start
 *
 * Then open http://localhost:3001, log in with a name in one tab and with another name in a private window.
 */

declare(strict_types=1);

use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Engine\Request as EngineRequest;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Session;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$io = new SocketIO(3000, [
    // The page is on another port, so the browser sends the session cookie only with credentials allowed.
    'cors' => [
        'origin' => static fn (string $origin, EngineRequest $request): bool =>
            parse_url($origin, PHP_URL_HOST) === parse_url('//' . $request->header('Host'), PHP_URL_HOST),
        'credentials' => true,
    ],
]);

// The logged-in user of the session in the socket's cookie, or null.
$user = static function (Socket $socket): ?string {
    parse_str(strtr($socket->handshake['headers']['cookie'] ?? '', ['; ' => '&']), $cookies);
    $sid = $cookies[Session::$name] ?? null;
    if (! is_string($sid) || preg_match('/^[a-zA-Z0-9,-]{16,256}$/', $sid) !== 1) {
        return null;
    }
    $name = (new Session($sid))->get('user');

    return is_string($name) ? $name : null;
};

// Runs before each connection: only logged-in sessions get in.
$io->use(static function (Socket $socket, Closure $next) use ($user): void {
    $name = $user($socket);
    if ($name === null) {
        $next(new ConnectionError('not logged in'));

        return;
    }
    $socket->data['user'] = $name;
    $next();
});

$io->on('connection', static function (Socket $socket) use ($io): void {
    $name = $socket->data['user'];
    $socket->on('whoami', static function (Closure $ack) use ($name): void {
        $ack($name);
    });
    $socket->on('chat', static function (string $text) use ($io, $name): void {
        $io->emit('chat', ['from' => $name, 'text' => $text]);
    });
});

// The page, with the login and logout of a plain PHP app.
$page = new Worker('http://0.0.0.0:3001');
$page->onMessage = static function (TcpConnection $connection, Request $request): void {
    $session = $request->session();
    if ($request->method() === 'POST' && $request->path() === '/login') {
        $name = $request->post('name');
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $connection->send(new Response(400, [], 'a name is required'));

            return;
        }
        $session->set('user', $name);
        $connection->send(new Response(204));

        return;
    }
    if ($request->method() === 'POST' && $request->path() === '/logout') {
        $session->flush();
        $connection->send(new Response(204));

        return;
    }
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
