<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\CloseReason;
use Marcolin\SocketIO\Engine\InvalidPacketException;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Socket;
use Marcolin\SocketIO\Engine\SocketState;
use Marcolin\SocketIO\Engine\Transport\Polling;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Marcolin\SocketIO\Engine\Transport\TransportException;
use Marcolin\SocketIO\Engine\Transport\TransportState;
use Marcolin\SocketIO\Engine\Transport\WebSocket;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\FakeWebSocketConnection;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Scenarios follow the official Engine.IO v4 test suite, with its timings.
 */
final class SocketTest extends TestCase
{
    private const PING_INTERVAL = 300;
    private const PING_TIMEOUT = 200;
    private const UPGRADE_TIMEOUT = 1000;

    private FakeScheduler $scheduler;

    /** @var list<string|Binary> */
    private array $messages = [];

    /** @var list<array{CloseReason, ?Throwable}> */
    private array $closes = [];

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
    }

    // Handshake

    public function testPollingHandshakeAnswersTheFirstGetWithTheOpenPacket(): void
    {
        [, $polling] = $this->pollingSocket(open: false);
        $get = $this->get($polling);

        self::assertSame(200, $get->status);
        $body = (string) $get->responseBody;
        self::assertStringStartsWith('0', $body);
        self::assertSame([
            'sid' => 'sid1',
            'upgrades' => ['websocket'],
            'pingInterval' => self::PING_INTERVAL,
            'pingTimeout' => self::PING_TIMEOUT,
            'maxPayload' => 1_000_000,
        ], json_decode(substr($body, 1), true));
    }

    public function testWebSocketHandshakeSendsTheOpenPacketRightAway(): void
    {
        [, $connection] = $this->webSocketSocket(open: false);

        self::assertCount(1, $connection->sent);
        $frame = $connection->sentData()[0];
        self::assertStringStartsWith('0', $frame);
        $open = json_decode(substr($frame, 1), true);
        self::assertIsArray($open);
        self::assertSame([], $open['upgrades']);
        self::assertSame('sid1', $open['sid']);
    }

    // Message

    public function testPollingDeliversMessagesAndEchoesThemBack(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $this->echo($socket);

        $post = $this->post($polling, "4test1\x1e4test2\x1ebAQIDBA==");
        $get = $this->get($polling);

        self::assertSame('ok', $post->responseBody);
        self::assertSame("4test1\x1e4test2\x1ebAQIDBA==", $get->responseBody);
        self::assertSame('test1', $this->messages[0]);
        self::assertEquals(new Binary("\x01\x02\x03\x04"), $this->messages[2]);
    }

    public function testWebSocketDeliversMessagesAndEchoesThemBack(): void
    {
        [$socket, $connection] = $this->webSocketSocket();
        $this->echo($socket);

        $connection->receive('4hello');
        $connection->receive("\x01\x02\x03\x04", true);

        self::assertSame([
            ['data' => '4hello', 'binary' => false],
            ['data' => "\x01\x02\x03\x04", 'binary' => true],
        ], $connection->sent);
    }

    public function testMessagesWaitInTheBufferForTheNextGet(): void
    {
        [$socket, $polling] = $this->pollingSocket();

        $socket->send('a');
        $socket->send(new Binary("\xff"));

        self::assertSame("4a\x1eb/w==", $this->get($polling)->responseBody);
    }

    public function testAnInvalidPollingPayloadClosesTheSession(): void
    {
        [$socket, $polling] = $this->pollingSocket();

        $post = $this->post($polling, 'abc');

        self::assertSame(400, $post->status);
        $this->assertClosed($socket, CloseReason::ParseError);
        self::assertInstanceOf(InvalidPacketException::class, $this->closes[0][1]);
    }

    public function testAnInvalidWebSocketFrameClosesTheSession(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $connection->receive('abc');

        $this->assertClosed($socket, CloseReason::ParseError);
        self::assertTrue($connection->closed);
    }

    // Heartbeat

    public function testWebSocketPingsAfterEveryIntervalWhileTheClientAnswers(): void
    {
        [$socket, $connection] = $this->webSocketSocket();
        $heartbeats = 0;
        $socket->on('heartbeat', function () use (&$heartbeats): void {
            $heartbeats++;
        });

        for ($i = 1; $i <= 3; $i++) {
            $this->scheduler->advance(self::PING_INTERVAL - 1);
            self::assertCount($i - 1, $connection->sent);
            $this->scheduler->advance(1);
            self::assertSame('2', $connection->sentData()[$i - 1]);

            $this->scheduler->advance(self::PING_TIMEOUT - 1);
            $connection->receive('3');
        }

        self::assertSame(3, $heartbeats);
        self::assertSame(SocketState::Open, $socket->state());
    }

    public function testPollingPingsThroughThePendingGet(): void
    {
        [$socket, $polling] = $this->pollingSocket();

        for ($i = 0; $i < 3; $i++) {
            $get = $this->get($polling);
            $this->scheduler->advance(self::PING_INTERVAL);
            self::assertSame('2', $get->responseBody);
            self::assertSame('ok', $this->post($polling, '3')->responseBody);
        }

        self::assertSame(SocketState::Open, $socket->state());
    }

    public function testWebSocketClosesWithoutPong(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $this->scheduler->advance(self::PING_INTERVAL + self::PING_TIMEOUT - 1);
        self::assertSame(SocketState::Open, $socket->state());

        $this->scheduler->advance(1);
        $this->assertClosed($socket, CloseReason::PingTimeout);
        self::assertTrue($connection->closed);
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testPollingClosesWithoutPong(): void
    {
        [$socket, $polling] = $this->pollingSocket();

        $this->scheduler->advance(self::PING_INTERVAL + self::PING_TIMEOUT);

        $this->assertClosed($socket, CloseReason::PingTimeout);
        self::assertSame(TransportState::Closing, $polling->state());
    }

    public function testOtherPacketsExtendThePingTimeoutOnlyOnce(): void
    {
        [$socket, $connection] = $this->webSocketSocket();
        $this->scheduler->advance(self::PING_INTERVAL);

        $this->scheduler->advance(150);
        $connection->receive('4still here');
        $this->scheduler->advance(150);
        $connection->receive('4again');
        self::assertSame(SocketState::Open, $socket->state());

        $this->scheduler->advance(50);
        $this->assertClosed($socket, CloseReason::PingTimeout);
    }

    public function testThePingTimeoutIsNotExtendedBeforeAPing(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $connection->receive('4early');
        $this->scheduler->advance(self::PING_INTERVAL + self::PING_TIMEOUT);

        $this->assertClosed($socket, CloseReason::PingTimeout);
    }

    public function testAPingFromTheClientClosesTheSession(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $connection->receive('2');

        $this->assertClosed($socket, CloseReason::TransportError);
        self::assertInstanceOf(TransportException::class, $this->closes[0][1]);
        self::assertSame('invalid heartbeat direction', $this->closes[0][1]->getMessage());
    }

    // Close

    public function testPollingCloseFromTheClientEndsThePendingGetWithNoop(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $get = $this->get($polling);

        $this->post($polling, '1');

        self::assertSame('6', $get->responseBody);
        $this->assertClosed($socket, CloseReason::TransportClose);
        self::assertSame(400, $this->get($polling)->status);
    }

    public function testWebSocketCloseFromTheClientClosesTheSession(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $connection->receive('1');

        $this->assertClosed($socket, CloseReason::TransportClose);
        self::assertTrue($connection->closed);
    }

    public function testAClosedWebSocketConnectionClosesTheSession(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $connection->close();

        $this->assertClosed($socket, CloseReason::TransportClose);
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testAConcurrentGetClosesTheSessionAndEndsThePendingOneWithClose(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $first = $this->get($polling);

        $second = $this->get($polling);

        self::assertSame('1', $first->responseBody);
        self::assertSame(400, $second->status);
        $this->assertClosed($socket, CloseReason::TransportError);
        self::assertSame(400, $this->get($polling)->status);
    }

    public function testACancelledGetClosesTheSession(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $get = $this->get($polling);

        $get->abort();

        $this->assertClosed($socket, CloseReason::TransportError);
    }

    public function testCloseOverWebSocketIsImmediate(): void
    {
        [$socket, $connection] = $this->webSocketSocket();

        $socket->close();

        $this->assertClosed($socket, CloseReason::ForcedClose);
        self::assertTrue($connection->closed);
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testCloseFlushesTheBufferFirst(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $socket->send('bye');

        $socket->close();
        $socket->send('ignored');

        self::assertSame(SocketState::Closing, $socket->state());
        self::assertSame('4bye', $this->get($polling)->responseBody);
        self::assertSame(SocketState::Closing, $socket->state());
        self::assertSame("6\x1e1", $this->get($polling)->responseBody);
        $this->assertClosed($socket, CloseReason::ForcedClose);
    }

    public function testCloseWithAPendingGetSendsTheClosePacket(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $get = $this->get($polling);

        $socket->close();

        self::assertSame('1', $get->responseBody);
        $this->assertClosed($socket, CloseReason::ForcedClose);
    }

    public function testDiscardClosesAtOnceAndDropsTheBuffer(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $socket->send('dropped');

        $socket->close(true);

        $this->assertClosed($socket, CloseReason::ForcedClose);
        self::assertSame(TransportState::Closed, $polling->state());
    }

    public function testDiscardEndsAnOrderlyCloseStillWaiting(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $socket->send('waiting');
        $socket->close();
        self::assertSame(SocketState::Closing, $socket->state());

        $socket->close(true);

        $this->assertClosed($socket, CloseReason::ForcedClose);
    }

    public function testDiscardWithAPendingGetSendsOnlyTheClosePacket(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $get = $this->get($polling);

        $socket->close(true);

        self::assertSame('1', $get->responseBody);
        $this->assertClosed($socket, CloseReason::ForcedClose);
    }

    public function testNothingIsSentOnceClosed(): void
    {
        [$socket, $connection] = $this->webSocketSocket();
        $socket->close();

        $socket->send('late');
        $socket->close();

        self::assertSame([], $connection->sent);
        self::assertCount(1, $this->closes);
    }

    // Upgrade

    public function testUpgradesFromPollingToWebSocket(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $events = [];
        $socket->on('upgrading', function (Transport $t) use (&$events): void {
            $events[] = 'upgrading:' . $t->name();
        });
        $socket->on('upgrade', function (Transport $t) use (&$events): void {
            $events[] = 'upgrade:' . $t->name();
        });
        $this->echo($socket);
        $connection = new FakeWebSocketConnection();
        $websocket = new WebSocket($connection);

        $socket->maybeUpgrade($websocket);
        $connection->receive('2probe');

        self::assertSame(['3probe'], $connection->sentData());
        self::assertTrue($socket->isUpgrading());
        self::assertSame('6', $this->get($polling)->responseBody);

        $connection->receive('5');
        $connection->receive('4hello');

        self::assertSame(['upgrading:websocket', 'upgrade:websocket'], $events);
        self::assertSame($websocket, $socket->transport());
        self::assertTrue($socket->isUpgraded());
        self::assertFalse($socket->isUpgrading());
        self::assertSame(TransportState::Closed, $polling->state());
        self::assertSame(['3probe', '4hello'], $connection->sentData());
    }

    public function testThePendingGetIsEndedWithNoopOnProbe(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $get = $this->get($polling);
        $connection = new FakeWebSocketConnection();

        $socket->maybeUpgrade(new WebSocket($connection));
        self::assertFalse($get->isAnswered());
        $connection->receive('2probe');

        self::assertSame('6', $get->responseBody);
    }

    public function testBufferedPacketsGoThroughTheNewTransport(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));
        $connection->receive('2probe');
        $this->get($polling);

        $socket->send('queued');
        $connection->receive('5');

        self::assertSame(['3probe', '4queued'], $connection->sentData());
    }

    public function testHttpRequestsAreRejectedAfterTheUpgrade(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $this->upgrade($socket);

        self::assertSame(400, $this->get($polling)->status);
        self::assertSame(400, $this->post($polling, '4late')->status);
        self::assertSame(SocketState::Open, $socket->state());
    }

    public function testAnotherWebSocketIsClosedAfterTheUpgrade(): void
    {
        [$socket] = $this->pollingSocket();
        $first = $this->upgrade($socket);
        $second = new FakeWebSocketConnection();

        $socket->maybeUpgrade(new WebSocket($second));
        $first->receive('4hello');

        self::assertTrue($second->closed);
        self::assertSame(['hello'], $this->messages);
    }

    public function testAnotherWebSocketIsClosedDuringTheUpgrade(): void
    {
        [$socket] = $this->pollingSocket();
        $first = new FakeWebSocketConnection();
        $second = new FakeWebSocketConnection();

        $socket->maybeUpgrade(new WebSocket($first));
        $socket->maybeUpgrade(new WebSocket($second));

        self::assertTrue($second->closed);
        self::assertFalse($first->closed);
    }

    public function testAnIncompleteUpgradeTimesOut(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));
        $connection->receive('2probe');
        for ($i = 0; $i < 3; $i++) {
            $this->scheduler->advance(self::PING_INTERVAL);
            $this->post($polling, '3');
        }
        self::assertFalse($connection->closed);

        $this->scheduler->advance(self::UPGRADE_TIMEOUT - 3 * self::PING_INTERVAL);

        self::assertTrue($connection->closed);
        self::assertFalse($socket->isUpgrading());
        self::assertSame($polling, $socket->transport());
        self::assertSame(SocketState::Open, $socket->state());
    }

    public function testAClosedProbeConnectionAbortsTheUpgrade(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));

        $connection->close();

        self::assertFalse($socket->isUpgrading());
        self::assertSame(SocketState::Open, $socket->state());
        self::assertSame($polling, $socket->transport());
        // The upgrade timer is gone; only the heartbeat is left.
        self::assertSame(1, $this->scheduler->pending());
    }

    public function testAnUnexpectedPacketAbortsTheUpgrade(): void
    {
        [$socket, $polling] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));

        $connection->receive('4too soon');

        self::assertTrue($connection->closed);
        self::assertFalse($socket->isUpgrading());
        self::assertSame($polling, $socket->transport());
        self::assertSame([], $this->messages);
    }

    public function testClosingTheSessionAbortsTheUpgrade(): void
    {
        [$socket] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));

        $this->scheduler->advance(self::PING_INTERVAL + self::PING_TIMEOUT);

        $this->assertClosed($socket, CloseReason::PingTimeout);
        self::assertTrue($connection->closed);
        self::assertFalse($socket->isUpgrading());
    }

    public function testAnUpgradeCompletedWhileClosingClosesTheNewTransport(): void
    {
        [$socket] = $this->pollingSocket();
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));
        $connection->receive('2probe');

        $socket->close();
        self::assertSame(SocketState::Closing, $socket->state());
        $connection->receive('5');

        $this->assertClosed($socket, CloseReason::ForcedClose);
        self::assertTrue($connection->closed);
    }

    public function testTheHeartbeatContinuesOverTheNewTransport(): void
    {
        [$socket] = $this->pollingSocket();
        $connection = $this->upgrade($socket);

        $this->scheduler->advance(self::PING_INTERVAL);
        self::assertSame('2', $connection->sentData()[1] ?? null);

        $connection->receive('3');
        $this->scheduler->advance(self::PING_TIMEOUT);
        self::assertSame(SocketState::Open, $socket->state());
    }

    // Helpers

    /**
     * @return array{Socket, Polling}
     */
    private function pollingSocket(bool $open = true): array
    {
        $polling = new Polling($this->scheduler);
        $socket = $this->socket($polling);
        if ($open) {
            $this->get($polling);
        }

        return [$socket, $polling];
    }

    /**
     * @return array{Socket, FakeWebSocketConnection}
     */
    private function webSocketSocket(bool $open = true): array
    {
        $connection = new FakeWebSocketConnection();
        $socket = $this->socket(new WebSocket($connection));
        if ($open) {
            $connection->sent = [];
        }

        return [$socket, $connection];
    }

    private function socket(Transport $transport): Socket
    {
        $socket = new Socket(
            'sid1',
            $transport,
            new ServerOptions(self::PING_INTERVAL, self::PING_TIMEOUT, 1_000_000, self::UPGRADE_TIMEOUT),
            $this->scheduler,
        );
        $socket->on('message', function (string|Binary $data): void {
            $this->messages[] = $data;
        });
        $socket->on('close', function (CloseReason $reason, ?Throwable $error): void {
            $this->closes[] = [$reason, $error];
        });

        return $socket;
    }

    /**
     * Completes an upgrade to a new WebSocket and returns its connection, with the probe frame cleared.
     */
    private function upgrade(Socket $socket): FakeWebSocketConnection
    {
        $connection = new FakeWebSocketConnection();
        $socket->maybeUpgrade(new WebSocket($connection));
        $connection->receive('2probe');
        $connection->receive('5');
        self::assertTrue($socket->isUpgraded());

        return $connection;
    }

    private function echo(Socket $socket): void
    {
        $socket->on('message', function (string|Binary $data) use ($socket): void {
            $socket->send($data);
        });
    }

    private function get(Polling $polling): FakeHttpExchange
    {
        $exchange = FakeHttpExchange::get();
        $polling->handleRequest($exchange);

        return $exchange;
    }

    private function post(Polling $polling, string $body): FakeHttpExchange
    {
        $exchange = FakeHttpExchange::post($body);
        $polling->handleRequest($exchange);

        return $exchange;
    }

    private function assertClosed(Socket $socket, CloseReason $reason): void
    {
        self::assertSame(SocketState::Closed, $socket->state());
        self::assertCount(1, $this->closes);
        self::assertSame($reason, $this->closes[0][0]);
    }
}
