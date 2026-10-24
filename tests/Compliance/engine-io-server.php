<?php

/**
 * The server the official Engine.IO v4 test suite expects: a pure Engine.IO server on port 3000,
 * path /engine.io/, that echoes every message back.
 *
 * Run by tests/Compliance/engine-io.sh: php tests/Compliance/engine-io-server.php start
 */

declare(strict_types=1);

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Cors;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Socket;
use Marcolin\SocketIO\Engine\Workerman\ServerBinding;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Workerman\Worker;

require __DIR__ . '/../../vendor/autoload.php';

// The options from the spec's "Test suite" section.
$server = new Server(
    new ServerOptions(
        pingInterval: 300,
        pingTimeout: 200,
        maxPayload: 1_000_000,
        path: '/engine.io/',
        cors: new Cors('*'),
    ),
    new WorkermanScheduler(),
);
$server->on('connection', static function (Socket $socket): void {
    $socket->on('message', static function (string|Binary $data) use ($socket): void {
        $socket->send($data);
    });
});

// Dual-stack: the suite connects to "localhost", which may resolve to ::1.
$worker = new Worker('tcp://[::]:3000');
$worker->name = 'engine-io-compliance';
(new ServerBinding($server))->bind($worker);

Worker::$pidFile = sys_get_temp_dir() . '/engine-io-compliance.pid';
Worker::$logFile = sys_get_temp_dir() . '/engine-io-compliance.log';
Worker::runAll();
