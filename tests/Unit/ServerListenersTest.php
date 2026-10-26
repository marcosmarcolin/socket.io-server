<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * The server's listeners, as the reference forwards every emitter method to the main namespace, and its
 * `new_namespace` event.
 */
final class ServerListenersTest extends TestCase
{
    private SocketIO $io;

    private int $calls = 0;

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
    }

    public function testNewNamespaceFiresOnceForEachOtherNamespace(): void
    {
        $created = [];
        $this->io->on('new_namespace', static function (Nsp $nsp) use (&$created): void {
            $created[] = $nsp->name;
        });

        $this->io->of('/');
        $this->io->of('/admin');
        $this->io->of('admin');
        $this->io->of('/chat');

        self::assertSame(['/admin', '/chat'], $created);
        self::assertCount(1, $this->io->sockets->listeners('new_namespace'));
    }

    public function testANewNamespaceCanGetAMiddleware(): void
    {
        $this->io->on('new_namespace', static function (Nsp $nsp): void {
            $nsp->use(static function (Socket $socket, Closure $next): void {
                $next(new ConnectionError('closed for now'));
            });
        });
        $this->io->of('/admin');

        $client = new SocketIOClient($this->io);
        $client->send('0/admin,');

        self::assertSame(['4/admin,{"message":"closed for now"}'], $client->received());
    }

    public function testOnceOffAndListenersReachTheMainNamespace(): void
    {
        $listener = function (): void {
            ++$this->calls;
        };
        $this->io->once('connection', $listener);
        self::assertSame([$listener], $this->io->listeners('connection'));
        self::assertSame([$listener], $this->io->sockets->listeners('connection'));

        $this->connect();
        $this->connect();
        self::assertSame(1, $this->calls);

        $this->io->on('connection', $listener);
        $this->io->off('connection', $listener);
        $this->connect();
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->io->listeners('connection'));
    }

    public function testTheServersOwnEventsStayOnIt(): void
    {
        $listener = static function (): void {
        };
        $this->io->on('workerStart', $listener);
        $this->io->on('connection', $listener);

        self::assertSame([$listener], $this->io->listeners('workerStart'));
        self::assertSame([], $this->io->sockets->listeners('workerStart'));
        self::assertSame(['workerStart', 'connection'], $this->io->eventNames());

        $this->io->removeListener('workerStart', $listener);
        self::assertSame(['connection'], $this->io->eventNames());

        $this->io->removeAllListeners();
        self::assertSame([], $this->io->eventNames());
    }

    public function testTheEmitterNamesOfTheReference(): void
    {
        $socket = $this->connect();
        $a = static function (): void {
        };
        $b = static function (): void {
        };
        $socket->on('chat', $a);
        $socket->on('chat', $b);
        $socket->on('typing', $a);

        self::assertSame(['chat', 'typing'], $socket->eventNames());

        $socket->removeListener('chat', $a);
        self::assertSame([$b], $socket->listeners('chat'));

        $socket->removeAllListeners('chat');
        self::assertSame(['typing'], $socket->eventNames());

        $socket->removeAllListeners();
        self::assertSame([], $socket->eventNames());
    }

    public function testMaxHttpBufferSizeIsAnAliasOfMaxPayload(): void
    {
        self::assertSame(2048, ServerOptions::fromArray(['maxHttpBufferSize' => 2048])->maxPayload);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        ServerOptions::fromArray(['maxHttpBufferSize' => 2048, 'maxPayload' => 4096]);
    }

    private function connect(): Socket
    {
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->received();
        $sockets = $this->io->sockets->sockets();

        return $sockets[array_key_last($sockets)];
    }
}
