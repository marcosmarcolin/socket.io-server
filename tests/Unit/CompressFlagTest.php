<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * compress(false) reaches the Engine.IO packets, as in the reference, from emits and broadcasts alike.
 */
final class CompressFlagTest extends TestCase
{
    /** @var list<array{string, bool}> each message written, and whether it may be compressed */
    private array $written = [];

    public function testEmitsMayBeCompressedUnlessToldOtherwise(): void
    {
        $io = $this->server();
        $socket = $this->connect($io);

        $socket->emit('a');
        $socket->compress(false)->emit('b');
        $socket->timeout(1000)->compress(false)->volatile->emit('c', static function (): void {
        });
        $socket->compress(false)->compress(true)->emit('d');

        self::assertSame([
            ['2["a"]', true],
            ['2["b"]', false],
            ['20["c"]', false],
            ['2["d"]', true],
        ], $this->written);
    }

    public function testBroadcastsCarryTheFlag(): void
    {
        $io = $this->server();
        $this->connect($io);

        $io->emit('a');
        $io->compress(false)->emit('b');
        $io->to('nowhere')->compress(false)->except('x')->emit('c');
        $io->of('/')->compress(false)->emit('d');
        $io->compress(false)->timeout(1000)->emit('e', static function (): void {
        });

        self::assertSame([['2["a"]', true], ['2["b"]', false], ['2["d"]', false], ['20["e"]', false]], $this->written);
    }

    public function testTheFlagCrossesProcesses(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->server();
        $a->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $broker->connect('A')));
        $b = $this->server(0x200);
        $b->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $broker->connect('B')));
        $this->connect($b);

        $a->compress(false)->emit('remote');
        $a->emit('remote-default');

        self::assertSame([['2["remote"]', false], ['2["remote-default"]', true]], $this->written);
    }

    private function server(int $firstSocketId = 1): SocketIO
    {
        return new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator($firstSocketId),
        );
    }

    private function connect(SocketIO $io): Socket
    {
        $client = new SocketIOClient($io);
        $client->send('0');
        $sockets = $io->sockets->sockets();
        $socket = $sockets[array_key_last($sockets)];
        $socket->conn->on('packetCreate', $this->recorder());

        return $socket;
    }

    /**
     * @return Closure(Packet): void
     */
    private function recorder(): Closure
    {
        return function (Packet $packet): void {
            $this->written[] = [$packet->data, $packet->compress];
        };
    }
}
