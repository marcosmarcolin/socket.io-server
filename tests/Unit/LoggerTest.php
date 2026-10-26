<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\ChannelAdapter;
use Marcolin\SocketIO\ConnectionError;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeChannelBroker;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `logger` option: the debug log, in the reference's scopes.
 */
final class LoggerTest extends TestCase
{
    private FakeScheduler $scheduler;

    /** @var list<string> each entry as "scope: message" */
    private array $log = [];

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
    }

    public function testASessionsLifeIsLogged(): void
    {
        $io = $this->server();
        $io->on('connection', static function (Socket $socket): void {
            $socket->use(static function (array $event, Closure $next): void {
                $next(new RuntimeException('not allowed'));
            });
        });

        $client = new SocketIOClient($io);
        $client->send('0');
        $client->send('2["chat"]');
        $client->send('1');

        self::assertSame([
            'engine: session sid1 opened over websocket from 10.0.0.1',
            'socket.io:socket: socket 000000000000000000000001 joined / over session sid1',
            'socket.io:socket: socket 000000000000000000000001: a middleware stopped "chat": not allowed',
            'socket.io:socket: socket 000000000000000000000001 left /: client namespace disconnect',
        ], $this->log);
    }

    public function testRefusalsAreLogged(): void
    {
        $io = $this->server();
        $io->of('/admin')->use(static function (Socket $socket, Closure $next): void {
            $next(new ConnectionError('unauthorized'));
        });
        $client = new SocketIOClient($io);
        $this->log = [];

        $client->send('0/admin,');
        $client->send('0/nowhere,');
        $io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=pigeon'));
        $client->send('9');

        self::assertSame([
            'socket.io:socket: /admin refused socket 000000000000000000000001: unauthorized',
            'socket.io:client: session sid1 asked for the unknown namespace /nowhere',
            'engine: refused /socket.io/?EIO=4&transport=pigeon: code 0, Transport unknown',
            'socket.io:client: session sid1 sent an invalid packet: unknown packet type 9',
            'engine:socket: session sid1 closed: forced close',
        ], $this->log);
    }

    public function testAConnectTimeoutIsLogged(): void
    {
        $io = $this->server();
        new SocketIOClient($io);
        $this->log = [];

        $this->scheduler->advance(1000);

        self::assertSame([
            'socket.io:client: session sid1 joined no namespace within 1000 ms',
            'engine:socket: session sid1 closed: forced close',
        ], $this->log);
    }

    public function testServersJoiningAndLeavingTheClusterAreLogged(): void
    {
        $broker = new FakeChannelBroker();
        $a = $this->server();
        $a->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $broker->connect('A')));
        $b = new SocketIO(scheduler: new FakeScheduler());
        $b->adapter(static fn (Nsp $nsp): Adapter => new ChannelAdapter($nsp, $broker->connect('B')));

        $b->close();

        self::assertCount(2, $this->log);
        $server = '/^socket\.io:adapter: \/: server [0-9a-f]{16} ';
        self::assertMatchesRegularExpression($server . 'joined; 2 servers$/', $this->log[0]);
        self::assertMatchesRegularExpression($server . 'left; 1 servers$/', $this->log[1]);
    }

    public function testTheOptionMustBeAClosure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"logger"');

        new SocketIO(options: ['logger' => 'stderr'], scheduler: new FakeScheduler());
    }

    private function server(): SocketIO
    {
        return new SocketIO(
            options: [
                'pingInterval' => 100_000,
                'pingTimeout' => 100_000,
                'connectTimeout' => 1000,
                'logger' => function (string $scope, string $message): void {
                    $this->log[] = $scope . ': ' . $message;
                },
            ],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
    }
}
