<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Protocol;

use Marcolin\SocketIO\Engine\Protocol\HttpOrWebSocket;
use Marcolin\SocketIO\Engine\Protocol\WebSocket;
use Marcolin\SocketIO\Tests\Fake\SocketPairConnection;
use Marcolin\SocketIO\Tests\Fake\WebSocketFrames;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

final class HttpOrWebSocketTest extends TestCase
{
    /** @var list<mixed> */
    private array $messages = [];

    private SocketPairConnection $pair;

    protected function setUp(): void
    {
        $worker = new Worker();
        $worker->protocol = HttpOrWebSocket::class;
        $worker->onMessage = function (TcpConnection $connection, mixed $data): void {
            $this->messages[] = $data;
            if ($data instanceof Request) {
                $connection->send(new Response(200, [], 'answer'));
            }
        };
        $this->pair = new SocketPairConnection($worker);
    }

    public function testHandsHttpRequestsToHttp(): void
    {
        $this->pair->write("GET /a?b=c HTTP/1.1\r\nHost: x\r\n\r\n");

        self::assertCount(1, $this->messages);
        self::assertInstanceOf(Request::class, $this->messages[0]);
        self::assertSame('/a', $this->messages[0]->path());
        self::assertStringStartsWith('HTTP/1.1 200 OK', $this->pair->read());
        self::assertSame(HttpOrWebSocket::class, $this->pair->connection->protocol);
    }

    public function testWaitsForTheCompleteHeaders(): void
    {
        $request = "GET /ws HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n";
        // Cut inside "Upgrade: websocket".
        $cut = (int) strpos($request, 'socket');

        $this->pair->write(substr($request, 0, $cut));

        self::assertSame([], $this->messages);
        self::assertSame(HttpOrWebSocket::class, $this->pair->connection->protocol);

        $this->pair->write(substr($request, $cut));

        self::assertSame(WebSocket::class, $this->pair->connection->protocol);
    }

    public function testSwitchesToWebSocketOnUpgrade(): void
    {
        $this->pair->write("GET /ws HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");

        self::assertSame(WebSocket::class, $this->pair->connection->protocol);
        self::assertStringStartsWith('HTTP/1.1 101', $this->pair->read());

        $this->pair->write(WebSocketFrames::client('hello'));

        self::assertSame(['hello'], $this->messages);
    }

    public function testTheUpgradeHeaderIsCaseInsensitive(): void
    {
        $this->pair->write("GET /ws HTTP/1.1\r\n"
            . str_replace('Upgrade: websocket', 'upgrade:  WebSocket', WebSocketFrames::upgradeHeaders()) . "\r\n");

        self::assertSame(WebSocket::class, $this->pair->connection->protocol);
    }

    public function testOnlyAGetCanUpgrade(): void
    {
        $this->pair->write("POST /ws HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "Content-Length: 0\r\n\r\n");

        self::assertSame(HttpOrWebSocket::class, $this->pair->connection->protocol);
        self::assertInstanceOf(Request::class, $this->messages[0] ?? null);
    }

    public function testAKeepAliveConnectionCanUpgradeLater(): void
    {
        $this->pair->write("GET /a HTTP/1.1\r\nHost: x\r\n\r\n");
        $this->pair->read();

        $this->pair->write("GET /ws HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");

        self::assertSame(WebSocket::class, $this->pair->connection->protocol);
    }
}
