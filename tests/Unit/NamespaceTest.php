<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NamespaceTest extends TestCase
{
    private SocketIO $io;

    /** @var list<Socket> */
    private array $connected = [];

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: [],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->io->on('connection', function (Socket $socket): void {
            $this->connected[] = $socket;
        });
    }

    public function testOfAddsTheLeadingSlashAndReusesNamespaces(): void
    {
        $admin = $this->io->of('admin');

        self::assertSame('/admin', $admin->name);
        self::assertSame($admin, $this->io->of('/admin'));
        self::assertSame($this->io->sockets, $this->io->of('/'));
    }

    public function testRejectsANamespaceWithAComma(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->io->of('/a,b');
    }

    public function testConnectListenersOnTheServerBelongToTheMainNamespace(): void
    {
        $connects = [];
        $this->io->on('connect', function (Socket $socket) use (&$connects): void {
            $connects[] = $socket->nsp->name;
        });
        $client = new SocketIOClient($this->io);

        $client->send('0');

        self::assertSame(['/'], $connects);
        self::assertCount(1, $this->connected);
        self::assertSame($this->connected, array_values($this->io->sockets->sockets()));
    }

    public function testMiddlewaresRunInOrderBeforeTheConnection(): void
    {
        $order = [];
        $this->io->use(function (Socket $socket, Closure $next) use (&$order): void {
            $order[] = 'first';
            $socket->data['user'] = 'ana';
            $next();
        });
        $this->io->use(function (Socket $socket, Closure $next) use (&$order): void {
            $order[] = 'second';
            $next();
        });

        (new SocketIOClient($this->io))->send('0');

        self::assertSame(['first', 'second'], $order);
        self::assertSame('ana', $this->connected[0]->data['user'] ?? null);
    }

    public function testAMiddlewareErrorRefusesTheConnection(): void
    {
        $this->io->use(static function (Socket $socket, Closure $next): void {
            $next(new RuntimeException('unauthorized'));
        });
        $client = new SocketIOClient($this->io);

        $client->send('0');

        self::assertSame(['4{"message":"unauthorized"}'], $client->received());
        self::assertSame([], $this->connected);
        self::assertSame([], $this->io->sockets->sockets());
        self::assertFalse($client->isClosed());
    }

    public function testAConnectionErrorSendsItsData(): void
    {
        $this->io->of('/admin')->use(static function (Socket $socket, Closure $next): void {
            $next(new ConnectionError('not an admin', ['role' => 'guest']));
        });
        $client = new SocketIOClient($this->io);

        $client->send('0/admin,');

        self::assertSame(['4/admin,{"message":"not an admin","data":{"role":"guest"}}'], $client->received());
    }

    public function testAnExceptionFromAMiddlewareRefusesTheConnection(): void
    {
        $this->io->use(static function (): void {
            throw new RuntimeException('database down');
        });
        $client = new SocketIOClient($this->io);

        $client->send('0');

        self::assertSame(['4{"message":"database down"}'], $client->received());
    }

    public function testAnExceptionAfterNextIsNotTakenForARefusal(): void
    {
        $this->io->use(static function (Socket $socket, Closure $next): void {
            $next();
        });
        $this->io->on('connection', static function (): void {
            throw new RuntimeException('bug in a listener');
        });
        $client = new SocketIOClient($this->io);

        $this->expectExceptionObject(new RuntimeException('bug in a listener'));

        $client->send('0');
    }

    public function testAMiddlewareCanCallNextLater(): void
    {
        $pending = null;
        $this->io->use(static function (Socket $socket, Closure $next) use (&$pending): void {
            $pending = $next;
        });
        $client = new SocketIOClient($this->io);
        $client->send('0');
        self::assertSame([], $client->received());
        self::assertInstanceOf(Closure::class, $pending);

        $pending();

        self::assertSame(['0{"sid":"000000000000000000000001"}'], $client->received());
        self::assertCount(1, $this->connected);
    }

    public function testOnlyTheFirstNextCounts(): void
    {
        $this->io->use(static function (Socket $socket, Closure $next): void {
            $next();
            $next(new RuntimeException('too late'));
            $next();
        });
        $client = new SocketIOClient($this->io);

        $client->send('0');

        self::assertSame(['0{"sid":"000000000000000000000001"}'], $client->received());
        self::assertCount(1, $this->connected);
    }

    public function testAConnectionClosedDuringAMiddlewareIsDropped(): void
    {
        $pending = null;
        $this->io->use(static function (Socket $socket, Closure $next) use (&$pending): void {
            $pending = $next;
        });
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $client->connection->close();
        self::assertInstanceOf(Closure::class, $pending);

        $pending();

        self::assertSame([], $this->connected);
        self::assertSame([], $this->io->sockets->sockets());
    }

    public function testMiddlewaresBelongToTheirNamespace(): void
    {
        $this->io->of('/admin')->use(static function (Socket $socket, Closure $next): void {
            $next(new RuntimeException('no'));
        });
        $client = new SocketIOClient($this->io);

        $client->send('0');

        self::assertCount(1, $this->connected);
    }

    public function testEachNamespaceGetsAnAdapterFromTheFactory(): void
    {
        $created = [];
        $this->io->of('/admin');

        $this->io->adapter(function (Nsp $nsp) use (&$created): Adapter {
            $created[] = $nsp->name;

            return new InMemoryAdapter($nsp);
        });
        $this->io->of('/chat');

        self::assertSame(['/', '/admin', '/chat'], $created);
    }

    public function testRejectsAnInvalidConnectTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocketIO(options: ['connectTimeout' => 0], scheduler: new FakeScheduler());
    }

    public function testPassesTheOtherOptionsToEngineIo(): void
    {
        $io = new SocketIO(
            options: ['connectTimeout' => 5000, 'pingInterval' => 1234, 'path' => '/ws'],
            scheduler: new FakeScheduler(),
        );

        self::assertSame(5000, $io->connectTimeout);
        self::assertSame(1234, $io->engine->options->pingInterval);
        self::assertSame('/ws/', $io->engine->options->path);
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SocketIO(options: ['conectTimeout' => 5000], scheduler: new FakeScheduler());
    }
}
