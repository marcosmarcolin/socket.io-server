<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Transport;

use Marcolin\SocketIO\Engine\InvalidPacketException;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Transport\TransportState;
use Marcolin\SocketIO\Engine\Transport\WebSocket;
use Marcolin\SocketIO\Tests\Fake\FakeWebSocketConnection;
use Marcolin\SocketIO\Tests\Fake\TransportRecorder;
use PHPUnit\Framework\TestCase;

final class WebSocketTest extends TestCase
{
    private FakeWebSocketConnection $connection;

    private WebSocket $websocket;

    private TransportRecorder $recorder;

    protected function setUp(): void
    {
        $this->connection = new FakeWebSocketConnection();
        $this->websocket = new WebSocket($this->connection);
        $this->recorder = new TransportRecorder($this->websocket);
    }

    public function testDescribesItself(): void
    {
        self::assertSame('websocket', $this->websocket->name());
        self::assertSame([], $this->websocket->upgrades());
        self::assertSame(TransportState::Open, $this->websocket->state());
        self::assertTrue($this->websocket->isWritable());
    }

    public function testDispatchesTextAndBinaryFrames(): void
    {
        $this->connection->receive('2probe');
        $this->connection->receive("\x01\x02", true);

        self::assertCount(2, $this->recorder->packets);
        [$text, $binary] = $this->recorder->packets;
        self::assertSame(PacketType::Ping, $text->type);
        self::assertSame('probe', $text->data);
        self::assertSame(PacketType::Message, $binary->type);
        self::assertSame("\x01\x02", $binary->data);
        self::assertTrue($binary->binary);
    }

    public function testABase64TextFrameIsABinaryMessage(): void
    {
        $this->connection->receive('bAQI=');

        self::assertSame("\x01\x02", $this->recorder->packets[0]->data ?? null);
        self::assertTrue($this->recorder->packets[0]->binary);
    }

    public function testWithoutBinarySupportBinaryPacketsGoAsBase64Text(): void
    {
        $websocket = new WebSocket($this->connection, supportsBinary: false);

        $websocket->send([new Packet(PacketType::Message, "\x01\x02", true), new Packet(PacketType::Message, 'hi')]);

        self::assertSame([
            ['data' => 'bAQI=', 'binary' => false],
            ['data' => '4hi', 'binary' => false],
        ], $this->connection->sent);
    }

    public function testAnInvalidFrameIsAnError(): void
    {
        $this->connection->receive('abc');

        self::assertSame([], $this->recorder->packets);
        self::assertInstanceOf(InvalidPacketException::class, $this->recorder->errors[0] ?? null);
    }

    public function testSendsOnePacketPerFrame(): void
    {
        $this->websocket->send([
            new Packet(PacketType::Message, 'hello'),
            new Packet(PacketType::Message, "\x01\x02", true),
            new Packet(PacketType::Ping),
        ]);

        self::assertSame([
            ['data' => '4hello', 'binary' => false],
            ['data' => "\x01\x02", 'binary' => true],
            ['data' => '2', 'binary' => false],
        ], $this->connection->sent);
        self::assertTrue($this->websocket->isWritable());
        self::assertSame(['drain'], $this->recorder->events);
    }

    public function testCloseRunsTheCallbackBeforeTheCloseEvent(): void
    {
        $order = [];
        $this->websocket->on('close', function () use (&$order): void {
            $order[] = 'close event';
        });

        $this->websocket->close(function () use (&$order): void {
            $order[] = 'callback';
        });

        self::assertSame(['callback', 'close event'], $order);
        self::assertTrue($this->connection->closed);
        self::assertSame(TransportState::Closed, $this->websocket->state());
        self::assertFalse($this->websocket->isWritable());
    }

    public function testAConnectionClosedByTheClientClosesTheTransportOnce(): void
    {
        $this->connection->close();
        $this->websocket->close();

        self::assertSame(TransportState::Closed, $this->websocket->state());
        self::assertSame(['close'], $this->recorder->events);
    }
}
