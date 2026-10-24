<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Protocol;

use Marcolin\SocketIO\Engine\Protocol\HttpOrWebSocket;
use Marcolin\SocketIO\Engine\Protocol\WebSocket;
use Marcolin\SocketIO\Tests\Fake\SocketPairConnection;
use Marcolin\SocketIO\Tests\Fake\WebSocketFrames;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

final class WebSocketTest extends TestCase
{
    /** @var list<array{string, bool}> */
    private array $messages = [];

    private SocketPairConnection $pair;

    protected function setUp(): void
    {
        $worker = new Worker();
        $worker->protocol = HttpOrWebSocket::class;
        $worker->onMessage = function (TcpConnection $connection, mixed $data): void {
            $this->messages[] = [is_string($data) ? $data : '', WebSocket::isBinary($connection)];
        };
        $this->pair = new SocketPairConnection($worker);
        $this->pair->write("GET /ws HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");
        $this->pair->read();
    }

    public function testTellsTextFromBinaryFrames(): void
    {
        $this->pair->write(WebSocketFrames::client('text'));
        $this->pair->write(WebSocketFrames::client("\x01\x02", 0x2));
        $this->pair->write(WebSocketFrames::client('text again'));

        self::assertSame([['text', false], ["\x01\x02", true], ['text again', false]], $this->messages);
    }

    public function testAFragmentedMessageKeepsTheTypeOfItsFirstFrame(): void
    {
        $this->pair->write(WebSocketFrames::client("\x01", 0x2, false));
        $this->pair->write(WebSocketFrames::client('ping', 0x9));
        $this->pair->write(WebSocketFrames::client("\x02", 0x0));

        self::assertSame([["\x01\x02", true]], $this->messages);
    }

    public function testSendsTextOrBinaryFrames(): void
    {
        WebSocket::send($this->pair->connection, '4hello', false);
        WebSocket::send($this->pair->connection, "\x01\x02", true);
        $this->pair->connection->send('plain');

        self::assertSame([
            ['opcode' => 0x1, 'data' => '4hello'],
            ['opcode' => 0x2, 'data' => "\x01\x02"],
            ['opcode' => 0x1, 'data' => 'plain'],
        ], WebSocketFrames::parse($this->pair->read()));
    }

    public function testAConnectionWithoutMessagesIsText(): void
    {
        self::assertFalse(WebSocket::isBinary($this->pair->connection));
    }
}
