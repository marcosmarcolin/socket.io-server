<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Workerman;

use Marcolin\SocketIO\Engine\Workerman\WorkermanWebSocketConnection;
use Marcolin\SocketIO\Engine\Workerman\WriteGroup;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SocketPairConnection;
use Marcolin\SocketIO\Tests\Fake\WebSocketFrames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class WorkermanWebSocketConnectionTest extends TestCase
{
    private FakeScheduler $scheduler;

    private SocketPairConnection $pair;

    private WorkermanWebSocketConnection $webSocket;

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        // No protocol: the frames reach the socket as the connection builds them.
        $this->pair = new SocketPairConnection(new Worker());
        $this->webSocket = new WorkermanWebSocketConnection($this->pair->connection, new WriteGroup($this->scheduler));
    }

    public function testTheFirstFrameOfAPassGoesOutAtOnce(): void
    {
        $this->webSocket->send('4alone', false);

        self::assertSame([['opcode' => 0x1, 'data' => '4alone']], WebSocketFrames::parse($this->pair->read()));
    }

    public function testTheNextFramesOfThePassWaitForItsEnd(): void
    {
        $this->webSocket->send('4one', false);
        $this->webSocket->send('4two', false);
        $this->webSocket->send('4three', false);

        self::assertSame([['opcode' => 0x1, 'data' => '4one']], WebSocketFrames::parse($this->pair->read()));
        self::assertSame(1, $this->scheduler->pending());
        $this->scheduler->advance(0);
        self::assertSame(
            [['opcode' => 0x1, 'data' => '4two'], ['opcode' => 0x1, 'data' => '4three']],
            WebSocketFrames::parse($this->pair->read()),
        );
    }

    public function testFramesOfOnePassGoOutTogetherInOrder(): void
    {
        $this->webSocket->send('4one', false);
        $this->webSocket->send("\x01\x02", true);
        $this->webSocket->send('4three', false);

        $this->scheduler->advance(0);

        self::assertSame([
            ['opcode' => 0x1, 'data' => '4one'],
            ['opcode' => 0x2, 'data' => "\x01\x02"],
            ['opcode' => 0x1, 'data' => '4three'],
        ], WebSocketFrames::parse($this->pair->read()));
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testOneTimerServesEveryConnection(): void
    {
        $group = new WriteGroup($this->scheduler);
        $other = new SocketPairConnection(new Worker());
        $first = new WorkermanWebSocketConnection($this->pair->connection, $group);
        $second = new WorkermanWebSocketConnection($other->connection, $group);

        $first->send('4one', false);
        $second->send('4two', false);
        $first->send('4three', false);
        self::assertSame(1, $this->scheduler->pending());
        $this->scheduler->advance(0);

        self::assertCount(2, WebSocketFrames::parse($this->pair->read()));
        self::assertCount(1, WebSocketFrames::parse($other->read()));
    }

    public function testEachPassGetsItsOwnWrite(): void
    {
        $this->webSocket->send('4one', false);
        $this->scheduler->advance(0);
        $this->pair->read();

        $this->webSocket->send('4two', false);
        $this->scheduler->advance(0);

        self::assertSame([['opcode' => 0x1, 'data' => '4two']], WebSocketFrames::parse($this->pair->read()));
    }

    public function testADeflatedFrameGoesAfterTheGroupedOnes(): void
    {
        $this->webSocket->send('4first', false);
        $this->webSocket->send('4grouped', false);

        $this->webSocket->send('4deflated', false, true);

        // Without the WebSocket protocol, Workerman writes the deflated message as it is, right after the group.
        $bytes = $this->pair->read();
        self::assertSame("\x81\x06" . '4first' . "\x81\x08" . '4grouped' . '4deflated', $bytes);
        // The group's timer may still fire: it finds nothing to write.
        $this->scheduler->advance(0);
        self::assertSame('', $this->pair->read());
    }

    public function testClosingSendsTheGroupedFramesFirst(): void
    {
        $this->webSocket->send('4first', false);
        $this->webSocket->send('4last words', false);

        $this->webSocket->close();

        self::assertSame([
            ['opcode' => 0x1, 'data' => '4first'],
            ['opcode' => 0x1, 'data' => '4last words'],
            ['opcode' => 0x8, 'data' => "\x03\xe8"],
        ], WebSocketFrames::parse($this->pair->read()));
    }

    public function testAClosedConnectionDropsWhatWasGrouped(): void
    {
        $this->webSocket->send('4sent', false);
        $this->webSocket->send('4never', false);

        $this->webSocket->closed();
        $this->scheduler->advance(0);

        self::assertSame([['opcode' => 0x1, 'data' => '4sent']], WebSocketFrames::parse($this->pair->read()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lengths(): iterable
    {
        yield 'one-byte length' => [125];
        yield 'two-byte length, smallest' => [126];
        yield 'two-byte length, largest' => [65535];
        yield 'eight-byte length' => [65536];
    }

    #[DataProvider('lengths')]
    public function testEncodesEachLengthAsRfc6455Says(int $length): void
    {
        $data = str_repeat('x', $length);

        $this->webSocket->send($data, false);
        $this->scheduler->advance(0);

        self::assertSame([['opcode' => 0x1, 'data' => $data]], WebSocketFrames::parse($this->pair->read()));
    }
}
