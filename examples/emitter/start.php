<?php

/**
 * Sending from your PHP app: the page's HTTP worker plays your app (a PHP-FPM request, say), which isn't a
 * Socket.IO server, and uses the Emitter to send through Redis to the clients of the Socket.IO server.
 *
 *   docker run --rm -p 6379:6379 redis:7.4.2-alpine
 *   php examples/emitter/start.php start
 *
 * Another Redis: REDIS_URL=redis://:password@host:6379 php examples/emitter/start.php start
 *
 * Then open http://localhost:3001 in two tabs and send a notification from one.
 */

declare(strict_types=1);

use Marcolin\SocketIO\Adapter\RedisAdapter;
use Marcolin\SocketIO\Emitter;
use Marcolin\SocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

$redisUrl = getenv('REDIS_URL') ?: 'redis://127.0.0.1:6379';

// The Socket.IO server: it only needs the Redis adapter to hear the Emitter.
$io = new SocketIO(3000);
$io->on('workerStart', static function () use ($io, $redisUrl): void {
    $io->adapter(RedisAdapter::factory($redisUrl));
});

// Your app. In PHP-FPM, the same two lines send from any request:
//   Emitter::redis('redis://127.0.0.1:6379')->emit('notification', $text);
$app = new Worker('http://0.0.0.0:3001');
$app->onMessage = static function (TcpConnection $connection, Request $request) use ($redisUrl): void {
    if ($request->method() === 'POST' && $request->path() === '/notify') {
        $text = $request->post('text');
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            $connection->send(new Response(400, [], 'text is required'));

            return;
        }
        Emitter::redis($redisUrl)->emit('notification', ['text' => $text, 'at' => date('H:i:s')]);
        $connection->send(new Response(204));

        return;
    }
    $html = (string) file_get_contents(__DIR__ . '/index.html');
    $connection->send(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
};

Worker::runAll();
