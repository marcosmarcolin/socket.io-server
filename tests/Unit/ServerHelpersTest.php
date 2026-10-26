<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The socket helpers on the server itself, as the reference's io.fetchSockets() and family, acting on "/".
 */
final class ServerHelpersTest extends TestCase
{
    private SocketIO $io;

    /** @var list<SocketIOClient> */
    private array $clients = [];

    /** @var list<Socket> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->io->of('/admin');
        for ($i = 0; $i < 2; $i++) {
            $client = new SocketIOClient($this->io);
            $client->send('0');
            $client->received();
            $this->clients[] = $client;
        }
        $this->sockets = array_values($this->io->sockets->sockets());
        // A socket of another namespace, which the helpers leave alone.
        $this->clients[0]->send('0/admin,');
        $this->clients[0]->received();
    }

    public function testFetchSocketsReturnsTheMainNamespacesSockets(): void
    {
        self::assertSame($this->sockets, $this->io->fetchSockets());
    }

    public function testFetchSocketsWithACallback(): void
    {
        $fetched = null;

        $this->io->fetchSockets(static function (?Throwable $error, array $sockets) use (&$fetched): void {
            $fetched = [$error, array_map(static fn (Socket|RemoteSocket $socket): string => $socket->id, $sockets)];
        });

        self::assertSame([null, [$this->sockets[0]->id, $this->sockets[1]->id]], $fetched);
    }

    public function testSocketsJoinAndLeave(): void
    {
        $this->io->socketsJoin(['a', 'b']);
        $this->io->socketsLeave('a');

        foreach ($this->sockets as $socket) {
            self::assertSame([$socket->id, 'b'], $socket->rooms);
        }
        $admin = array_values($this->io->of('/admin')->sockets())[0];
        self::assertSame([$admin->id], $admin->rooms);
    }

    public function testDisconnectSockets(): void
    {
        $this->io->disconnectSockets();

        self::assertFalse($this->sockets[0]->connected);
        self::assertFalse($this->sockets[1]->connected);
        self::assertSame(['1'], $this->clients[1]->received());
        self::assertCount(1, $this->io->of('/admin')->sockets());
    }

    public function testDisconnectSocketsCanCloseTheConnections(): void
    {
        $this->io->disconnectSockets(true);

        self::assertTrue($this->clients[0]->isClosed());
        self::assertTrue($this->clients[1]->isClosed());
    }
}
