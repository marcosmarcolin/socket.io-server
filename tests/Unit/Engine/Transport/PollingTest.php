<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Transport;

use LogicException;
use Marcolin\SocketIO\Engine\InvalidPacketException;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Transport\Polling;
use Marcolin\SocketIO\Engine\Transport\TransportException;
use Marcolin\SocketIO\Engine\Transport\TransportState;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\TransportRecorder;
use PHPUnit\Framework\TestCase;

final class PollingTest extends TestCase
{
    private FakeScheduler $scheduler;

    private Polling $polling;

    private TransportRecorder $recorder;

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->polling = new Polling($this->scheduler, 1000);
        $this->recorder = new TransportRecorder($this->polling);
    }

    public function testDescribesItself(): void
    {
        self::assertSame('polling', $this->polling->name());
        self::assertSame(['websocket'], $this->polling->upgrades());
        self::assertSame(TransportState::Open, $this->polling->state());
        self::assertFalse($this->polling->isWritable());
    }

    public function testAGetMakesItWritableAndWaits(): void
    {
        $get = FakeHttpExchange::get();

        $this->polling->handleRequest($get);

        self::assertTrue($this->polling->isWritable());
        self::assertFalse($get->isAnswered());
        self::assertSame(['ready'], $this->recorder->events);
    }

    public function testSendAnswersThePendingGet(): void
    {
        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);

        $this->polling->send([
            new Packet(PacketType::Message, 'hello'),
            new Packet(PacketType::Ping),
            new Packet(PacketType::Message, "\x01\x02\x03\x04", true),
        ]);

        self::assertSame(200, $get->status);
        self::assertSame("4hello\x1e2\x1ebAQIDBA==", $get->responseBody);
        self::assertSame('text/plain; charset=UTF-8', $get->responseHeaders['Content-Type']);
        self::assertSame('no-store', $get->responseHeaders['Cache-Control']);
        self::assertFalse($this->polling->isWritable());
        self::assertSame(['ready', 'drain'], $this->recorder->events);
    }

    public function testSendWithoutPendingGetIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        $this->polling->send([new Packet(PacketType::Message, 'lost')]);
    }

    public function testAPostDispatchesItsPacketsInOrder(): void
    {
        $post = FakeHttpExchange::post("4hello\x1e3\x1ebAQIDBA==");

        $this->polling->handleRequest($post);

        self::assertSame(200, $post->status);
        self::assertSame('ok', $post->responseBody);
        self::assertSame('text/html', $post->responseHeaders['Content-Type']);
        self::assertSame('no-store', $post->responseHeaders['Cache-Control']);
        self::assertSame(
            [
                [PacketType::Message, 'hello', false],
                [PacketType::Pong, '', false],
                [PacketType::Message, "\x01\x02\x03\x04", true],
            ],
            array_map(static fn (Packet $p): array => [$p->type, $p->data, $p->binary], $this->recorder->packets),
        );
    }

    public function testAnInvalidPayloadIsRejectedWithoutDispatchingAnyPacket(): void
    {
        $post = FakeHttpExchange::post("4hello\x1eabc");

        $this->polling->handleRequest($post);

        self::assertSame(400, $post->status);
        self::assertSame([], $this->recorder->packets);
        self::assertCount(1, $this->recorder->errors);
        self::assertInstanceOf(InvalidPacketException::class, $this->recorder->errors[0]);
    }

    public function testAnEmptyPostIsInvalid(): void
    {
        $post = FakeHttpExchange::post('');

        $this->polling->handleRequest($post);

        self::assertSame(400, $post->status);
        self::assertInstanceOf(InvalidPacketException::class, $this->recorder->errors[0] ?? null);
    }

    public function testABinaryBodyIsRejected(): void
    {
        $post = FakeHttpExchange::post("\x01\x02", ['content-type' => 'Application/Octet-Stream; charset=binary']);

        $this->polling->handleRequest($post);

        self::assertSame(400, $post->status);
        self::assertSame([], $this->recorder->packets);
        self::assertInstanceOf(TransportException::class, $this->recorder->errors[0] ?? null);
        self::assertSame('invalid content', $this->recorder->errors[0]->getMessage());
    }

    public function testAConcurrentGetIsAnError(): void
    {
        $first = FakeHttpExchange::get();
        $second = FakeHttpExchange::get();
        $this->polling->handleRequest($first);

        $this->polling->handleRequest($second);

        self::assertSame(400, $second->status);
        self::assertFalse($first->isAnswered());
        self::assertSame('overlap from client', ($this->recorder->errors[0] ?? null)?->getMessage());
    }

    public function testClosingOnAConcurrentGetAnswersThePendingOneWithClose(): void
    {
        // What Engine\Socket does on a transport error.
        $this->polling->on('error', function (): void {
            $this->polling->close();
        });
        $first = FakeHttpExchange::get();
        $second = FakeHttpExchange::get();
        $this->polling->handleRequest($first);

        $this->polling->handleRequest($second);

        self::assertSame('1', $first->responseBody);
        self::assertSame(400, $second->status);
        self::assertSame(TransportState::Closed, $this->polling->state());
    }

    public function testAnAbortedGetIsAnError(): void
    {
        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);

        $get->abort();

        self::assertFalse($this->polling->isWritable());
        self::assertSame('poll connection closed prematurely', ($this->recorder->errors[0] ?? null)?->getMessage());
    }

    public function testClosingTheConnectionOfAnAnsweredGetIsIgnored(): void
    {
        $first = FakeHttpExchange::get();
        $this->polling->handleRequest($first);
        $this->polling->send([new Packet(PacketType::Noop)]);
        $second = FakeHttpExchange::get();
        $this->polling->handleRequest($second);

        $first->abort();

        self::assertTrue($this->polling->isWritable());
        self::assertSame([], $this->recorder->errors);
    }

    public function testAReceivedCloseEndsThePendingGetWithNoop(): void
    {
        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);
        $post = FakeHttpExchange::post("4before\x1e1\x1e4after");

        $this->polling->handleRequest($post);

        self::assertSame('6', $get->responseBody);
        self::assertSame('ok', $post->responseBody);
        self::assertCount(1, $this->recorder->packets);
        self::assertSame('before', $this->recorder->packets[0]->data);
        self::assertSame(TransportState::Closed, $this->polling->state());
        self::assertSame(['ready', 'packet', 'drain', 'close'], $this->recorder->events);
    }

    public function testAReceivedCloseWithoutPendingGetClosesRightAway(): void
    {
        $this->polling->handleRequest(FakeHttpExchange::post('1'));

        self::assertSame(TransportState::Closed, $this->polling->state());
        self::assertSame(['close'], $this->recorder->events);
    }

    public function testCloseWhileWritableSendsClosePacket(): void
    {
        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);
        $closed = 0;

        $this->polling->close(function () use (&$closed): void {
            $closed++;
        });

        self::assertSame('1', $get->responseBody);
        self::assertSame(1, $closed);
        self::assertSame(TransportState::Closed, $this->polling->state());
        self::assertSame(['ready', 'drain', 'close'], $this->recorder->events);
    }

    public function testCloseWhileNotWritableWaitsForTheNextGet(): void
    {
        $closed = 0;
        $this->polling->close(function () use (&$closed): void {
            $closed++;
        });

        self::assertSame(TransportState::Closing, $this->polling->state());
        self::assertSame(0, $closed);

        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);

        self::assertSame("6\x1e1", $get->responseBody);
        self::assertSame(1, $closed);
        self::assertSame(TransportState::Closed, $this->polling->state());
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testPacketsSentToTheNextGetCarryTheClosePacket(): void
    {
        $this->polling->close();
        $this->polling->on('ready', function (): void {
            $this->polling->send([new Packet(PacketType::Message, 'last')]);
        });

        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);

        self::assertSame("4last\x1e1", $get->responseBody);
        self::assertSame(TransportState::Closed, $this->polling->state());
    }

    public function testClosingRightAfterFlushingOnReadyWaitsForTheNextGet(): void
    {
        // Engine\Socket flushes its buffer on ready and closes on drain.
        $this->polling->once('ready', function (): void {
            $this->polling->send([new Packet(PacketType::Message, 'last')]);
        });
        $this->polling->once('drain', function (): void {
            $this->polling->close();
        });
        $first = FakeHttpExchange::get();

        $this->polling->handleRequest($first);

        self::assertSame('4last', $first->responseBody);
        self::assertSame(TransportState::Closing, $this->polling->state());

        $second = FakeHttpExchange::get();
        $this->polling->handleRequest($second);

        self::assertSame("6\x1e1", $second->responseBody);
        self::assertSame(TransportState::Closed, $this->polling->state());
    }

    public function testCloseGivesUpWaitingForAGetAfterTheTimeout(): void
    {
        $closed = 0;
        $this->polling->close(function () use (&$closed): void {
            $closed++;
        });

        $this->scheduler->advance(999);
        self::assertSame(TransportState::Closing, $this->polling->state());

        $this->scheduler->advance(1);
        self::assertSame(1, $closed);
        self::assertSame(TransportState::Closed, $this->polling->state());

        $get = FakeHttpExchange::get();
        $this->polling->handleRequest($get);
        self::assertSame(400, $get->status);
        self::assertSame(1, $closed);
    }

    public function testADiscardedTransportClosesRightAway(): void
    {
        $this->polling->discard();
        $closed = 0;

        $this->polling->close(function () use (&$closed): void {
            $closed++;
        });

        self::assertSame(1, $closed);
        self::assertSame(TransportState::Closed, $this->polling->state());
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testCloseIsIgnoredOnceStarted(): void
    {
        $calls = 0;
        $count = function () use (&$calls): void {
            $calls++;
        };
        $this->polling->discard();

        $this->polling->close($count);
        $this->polling->close($count);

        self::assertSame(1, $calls);
        self::assertSame(['close'], $this->recorder->events);
    }

    public function testRequestsAfterCloseAreRejected(): void
    {
        $this->polling->handleRequest(FakeHttpExchange::post('1'));
        $get = FakeHttpExchange::get();
        $post = FakeHttpExchange::post('4late');

        $this->polling->handleRequest($get);
        $this->polling->handleRequest($post);

        self::assertSame(400, $get->status);
        self::assertSame(400, $post->status);
        self::assertSame([], $this->recorder->packets);
    }

    public function testOtherMethodsAreRejected(): void
    {
        $put = new FakeHttpExchange('PUT', '4hello');

        $this->polling->handleRequest($put);

        self::assertSame(400, $put->status);
        self::assertSame([], $this->recorder->events);
        self::assertSame(TransportState::Open, $this->polling->state());
    }
}
