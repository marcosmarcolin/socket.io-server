<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;
use WeakReference;

/**
 * A server lives for days: what a closed connection used must be freed, or memory grows with every client that
 * comes and goes.
 */
final class MemoryTest extends TestCase
{
    private SocketIO $io;

    protected function setUp(): void
    {
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
    }

    public function testAClosedConnectionIsFreed(): void
    {
        [$session, $socket] = $this->connectAndClose(static function (SocketIOClient $client): void {
            $client->connection->close();
        });

        self::assertNull($session->get(), 'the Engine.IO session is still held');
        self::assertNull($socket->get(), 'the socket is still held');
    }

    public function testAConnectionTheServerClosesIsFreed(): void
    {
        [$session, $socket] = $this->connectAndClose(function (): void {
            $this->io->disconnectSockets(true);
        });

        self::assertNull($session->get(), 'the Engine.IO session is still held');
        self::assertNull($socket->get(), 'the socket is still held');
    }

    /**
     * Connects a client to "/", closes it the given way, drops every reference the test held, and collects.
     *
     * @param \Closure(SocketIOClient): void $close
     * @return array{WeakReference<EngineSocket>, WeakReference<Socket>}
     */
    private function connectAndClose(\Closure $close): array
    {
        $sessions = [];
        $this->io->engine->on('connection', static function (EngineSocket $session) use (&$sessions): void {
            $sessions[] = WeakReference::create($session);
        });
        $client = new SocketIOClient($this->io);
        $client->send('0');
        $socket = WeakReference::create(array_values($this->io->sockets->sockets())[0]);

        $close($client);
        unset($client);
        gc_collect_cycles();

        return [$sessions[0], $socket];
    }
}
