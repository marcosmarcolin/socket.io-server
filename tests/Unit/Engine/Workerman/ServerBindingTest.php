<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\CloseReason;
use Marcolin\SocketIO\Engine\Cors;
use Marcolin\SocketIO\Engine\Protocol\HttpOrWebSocket;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Socket;
use Marcolin\SocketIO\Engine\Workerman\ServerBinding;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketPairConnection;
use Marcolin\SocketIO\Tests\Fake\WebSocketFrames;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Worker;

/**
 * The Engine.IO server behind real Workerman connections and protocols.
 */
final class ServerBindingTest extends TestCase
{
    private const MAX_PAYLOAD = 1000;

    private Server $server;

    private Worker $worker;

    /** @var list<Socket> */
    private array $connections = [];

    /** @var list<Throwable> */
    private array $errors = [];

    private ServerBinding $binding;

    protected function setUp(): void
    {
        $this->server = new Server(
            new ServerOptions(maxPayload: self::MAX_PAYLOAD, path: '/engine.io/'),
            new FakeScheduler(),
            new SequentialSessionIdGenerator(),
        );
        $this->server->on('connection', function (Socket $socket): void {
            $this->connections[] = $socket;
            $socket->on('message', static function (string|Binary $data) use ($socket): void {
                $socket->send($data);
            });
        });
        $this->worker = new Worker();
        (new ServerBinding($this->server, function (Throwable $error): void {
            $this->errors[] = $error;
        }))->bind($this->worker);
    }

    public function testTakesOverTheWorker(): void
    {
        self::assertSame(HttpOrWebSocket::class, $this->worker->protocol);
        self::assertNotNull($this->worker->onConnect);
        self::assertNotNull($this->worker->onMessage);
        self::assertNotNull($this->worker->onWebSocketConnect);
        self::assertNotNull($this->worker->onWebSocketConnected);
        self::assertNotNull($this->worker->onClose);
    }

    // HTTP long-polling

    public function testPollingHandshake(): void
    {
        $pair = $this->connect();

        $response = $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringContainsStringIgnoringCase("Content-Type: text/plain; charset=UTF-8\r\n", $response);
        self::assertStringContainsStringIgnoringCase("Access-Control-Allow-Origin: *\r\n", $response);
        self::assertStringContainsString("\r\n\r\n0{\"sid\":\"sid1\"", $response);
        self::assertSame(1, $this->server->clientsCount());
    }

    public function testCorsHeadersWithCredentialsReachTheWire(): void
    {
        $server = new Server(
            new ServerOptions(path: '/engine.io/', cors: new Cors(
                static fn (string $origin, Request $request): bool => $request->header('Host') === 'app.test:3000',
                true,
            )),
            new FakeScheduler(),
        );
        $worker = new Worker();
        (new ServerBinding($server))->bind($worker);
        $pair = new SocketPairConnection($worker);

        $pair->write("GET /engine.io/?EIO=4&transport=polling HTTP/1.1\r\nHost: app.test:3000\r\n"
            . "Origin: https://app.test\r\nCookie: session=abc\r\n\r\n");
        $response = $pair->read();

        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringContainsStringIgnoringCase("Access-Control-Allow-Origin: https://app.test\r\n", $response);
        self::assertStringContainsStringIgnoringCase("Access-Control-Allow-Credentials: true\r\n", $response);
        self::assertStringContainsStringIgnoringCase("Vary: Origin\r\n", $response);
    }

    public function testPollingRoundTripOnOneKeepAliveConnection(): void
    {
        $pair = $this->connect();
        $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        $post = $this->post($pair, '/engine.io/?EIO=4&transport=polling&sid=sid1', "4hello\x1ebAQIDBA==");
        $poll = $this->get($pair, '/engine.io/?EIO=4&transport=polling&sid=sid1');

        self::assertStringEndsWith("\r\n\r\nok", $post);
        self::assertStringEndsWith("\r\n\r\n4hello\x1ebAQIDBA==", $poll);
    }

    public function testErrorsAreJson(): void
    {
        $response = $this->get($this->connect(), '/engine.io/?transport=polling');

        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $response);
        self::assertStringContainsStringIgnoringCase("Content-Type: application/json\r\n", $response);
        self::assertStringEndsWith('{"code":5,"message":"Unsupported protocol version"}', $response);
    }

    public function testClosingAPendingPollClosesTheSession(): void
    {
        $this->get($this->connect(), '/engine.io/?EIO=4&transport=polling');
        $poll = $this->connect();
        $this->get($poll, '/engine.io/?EIO=4&transport=polling&sid=sid1');
        $reasons = [];
        $this->connections[0]->on('close', function (CloseReason $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $poll->closeClient();

        self::assertSame([CloseReason::TransportError], $reasons);
        self::assertSame(0, $this->server->clientsCount());
    }

    public function testClosingAConnectionWhoseRequestWasAnsweredKeepsTheSession(): void
    {
        $pair = $this->connect();
        $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        $pair->closeClient();

        self::assertSame(1, $this->server->clientsCount());
    }

    public function testATooLargeBodyIsRefused(): void
    {
        $pair = $this->connect();
        $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        $body = '4' . str_repeat('a', self::MAX_PAYLOAD);
        $response = $this->post($pair, '/engine.io/?EIO=4&transport=polling&sid=sid1', $body);

        self::assertStringStartsWith('HTTP/1.1 413', $response);
        self::assertSame(1, $this->server->clientsCount());
    }

    public function testLimitsThePackageSizeFromMaxPayload(): void
    {
        $pair = $this->connect();
        self::assertSame(self::MAX_PAYLOAD + 16_384, $pair->connection->maxPackageSize);

        $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');

        self::assertSame(self::MAX_PAYLOAD + 14, $pair->connection->maxPackageSize);
    }

    // WebSocket

    public function testWebSocketHandshakeAndEcho(): void
    {
        $pair = $this->connect();

        [$head, $frames] = $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');
        $pair->write(WebSocketFrames::client('4hello'));
        $pair->write(WebSocketFrames::client("\x01\x02", 0x2));

        self::assertStringStartsWith('HTTP/1.1 101', $head);
        self::assertCount(1, $frames);
        self::assertSame(0x1, $frames[0]['opcode']);
        self::assertStringStartsWith('0{"sid":"sid1","upgrades":[]', $frames[0]['data']);
        self::assertSame([
            ['opcode' => 0x1, 'data' => '4hello'],
            ['opcode' => 0x2, 'data' => "\x01\x02"],
        ], WebSocketFrames::parse($pair->read()));
    }

    public function testWhatAMiddlewareAttachesBeforeThe101StaysOnTheSession(): void
    {
        $this->server->use(static function (Request $request, Closure $next): void {
            $request->attributes['user'] = 'ana';
            $next();
        });
        $pair = $this->connect();

        [$head] = $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');

        self::assertStringStartsWith('HTTP/1.1 101', $head);
        self::assertSame(['user' => 'ana'], $this->server->session('sid1')?->request->attributes);
    }

    public function testThe101AcceptsTheFirstSubprotocolOffered(): void
    {
        $pair = $this->connect();
        $pair->write("GET /engine.io/?EIO=4&transport=websocket HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders()
            . "Sec-WebSocket-Protocol: chat, superchat\r\n\r\n");

        [$head] = WebSocketFrames::afterHandshake($pair->read());

        self::assertStringStartsWith('HTTP/1.1 101', $head);
        self::assertMatchesRegularExpression('/\r\nSec-WebSocket-Protocol: chat(\r\n|$)/', $head);
    }

    public function testAnInvalidRequestGetsAnHttpError(): void
    {
        $pair = $this->connect();

        $pair->write("GET /engine.io/?transport=websocket HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");

        $response = $pair->read();
        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $response);
        self::assertStringEndsWith("\r\n\r\nUnsupported protocol version", $response);
        self::assertTrue($pair->isClosed());
        self::assertSame([], $this->connections);
    }

    public function testAllowRequestRefusesAWebSocketWithAnHttpErrorAndNoSession(): void
    {
        $calls = 0;
        $pair = $this->refusingPair('Sessão inválida', $calls);

        $pair->write(
            "GET /engine.io/?EIO=4&transport=websocket HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n",
        );

        $response = $pair->read();
        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $response);
        self::assertStringEndsWith("\r\n\r\nSessão inválida", $response);
        self::assertStringContainsString('Content-Length: ' . strlen('Sessão inválida') . "\r\n", $response);
        self::assertTrue($pair->isClosed());
        self::assertSame(1, $calls);
        self::assertSame([], $this->connections);
        self::assertSame([], $this->errors);
    }

    public function testAWebSocketStillClosingAfterARefusalGetsNoSession(): void
    {
        $calls = 0;
        $pair = $this->refusingPair('Sessão inválida', $calls);
        $target = '/engine.io/?EIO=4&transport=websocket';
        $pair->write("GET $target HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");

        // Workerman makes this call when the refusal is still in the send buffer.
        $this->binding->onWebSocketConnected(
            $pair->connection,
            new WorkermanRequest("GET $target HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n"),
        );

        self::assertSame(1, $calls);
        self::assertSame([], $this->connections);
        self::assertSame([], $this->errors);
    }

    public function testAllowRequestRefusesAPollWithA403(): void
    {
        $calls = 0;
        $pair = $this->refusingPair('Sessão inválida', $calls);

        $response = $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        self::assertStringStartsWith('HTTP/1.1 403 Forbidden', $response);
        self::assertStringEndsWith("\r\n\r\n" . '{"code":4,"message":"Sessão inválida"}', $response);
        self::assertSame([], $this->connections);
    }

    public function testClosingTheWebSocketClosesTheSession(): void
    {
        $pair = $this->connect();
        $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');
        $reasons = [];
        $this->connections[0]->on('close', function (CloseReason $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $pair->closeClient();

        self::assertSame([CloseReason::TransportClose], $reasons);
        self::assertSame(0, $this->server->clientsCount());
    }

    public function testClosingTheSessionSendsACloseFrame(): void
    {
        $pair = $this->connect();
        $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');

        $this->connections[0]->close();

        self::assertSame([['opcode' => 0x8, 'data' => "\x03\xe8"]], WebSocketFrames::parse($pair->read()));
        self::assertTrue($pair->isClosed());
    }

    public function testUpgradesFromPolling(): void
    {
        $this->get($this->connect(), '/engine.io/?EIO=4&transport=polling');
        $poll = $this->connect();
        $this->get($poll, '/engine.io/?EIO=4&transport=polling&sid=sid1');
        $webSocket = $this->connect();

        [$head] = $this->openWebSocket($webSocket, '/engine.io/?EIO=4&transport=websocket&sid=sid1');
        $webSocket->write(WebSocketFrames::client('2probe'));

        self::assertStringStartsWith('HTTP/1.1 101', $head);
        self::assertSame([['opcode' => 0x1, 'data' => '3probe']], WebSocketFrames::parse($webSocket->read()));
        self::assertStringEndsWith("\r\n\r\n6", $poll->read());

        $webSocket->write(WebSocketFrames::client('5'));
        $webSocket->write(WebSocketFrames::client('4hello'));

        self::assertTrue($this->connections[0]->isUpgraded());
        self::assertSame([['opcode' => 0x1, 'data' => '4hello']], WebSocketFrames::parse($webSocket->read()));
    }

    // Exceptions

    public function testAnExceptionAfterTheAnswerClosesTheConnectionAndIsReported(): void
    {
        $this->server->on('connection', static function (): void {
            throw new RuntimeException('boom');
        });
        $pair = $this->connect();

        $response = $this->get($pair, '/engine.io/?EIO=4&transport=polling');

        // The open packet went out before the listener threw, so there is no 500.
        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertTrue($pair->isClosed());
        self::assertSame('boom', ($this->errors[0] ?? null)?->getMessage());
    }

    public function testAnExceptionBeforeAnyAnswerGets500(): void
    {
        $pair = $this->connect();
        $this->get($pair, '/engine.io/?EIO=4&transport=polling');
        $this->connections[0]->on('message', static function (): void {
            throw new RuntimeException('boom');
        });

        $response = $this->post($pair, '/engine.io/?EIO=4&transport=polling&sid=sid1', '4hello');

        self::assertStringStartsWith('HTTP/1.1 500', $response);
        self::assertTrue($pair->isClosed());
        self::assertCount(1, $this->errors);
    }

    public function testAnExceptionDuringAWebSocketHandshakeClosesTheConnection(): void
    {
        $this->server->on('connection', static function (): void {
            throw new RuntimeException('boom');
        });
        $pair = $this->connect();

        $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');

        self::assertTrue($pair->isClosed());
        self::assertSame('boom', ($this->errors[0] ?? null)?->getMessage());
    }

    public function testAnExceptionFromAWebSocketMessageClosesTheConnection(): void
    {
        $pair = $this->connect();
        $this->openWebSocket($pair, '/engine.io/?EIO=4&transport=websocket');
        $this->connections[0]->on('message', static function (): void {
            throw new RuntimeException('boom');
        });

        $pair->write(WebSocketFrames::client('4hello'));

        self::assertTrue($pair->isClosed());
        self::assertCount(1, $this->errors);
    }

    // Helpers

    /**
     * A connection to a server whose allowRequest refuses every handshake with $message.
     */
    private function refusingPair(string $message, int &$calls): SocketPairConnection
    {
        $this->server = new Server(
            new ServerOptions(path: '/engine.io/', allowRequest: static function () use ($message, &$calls): string {
                ++$calls;

                return $message;
            }),
            new FakeScheduler(),
            new SequentialSessionIdGenerator(),
        );
        $this->server->on('connection', function (Socket $socket): void {
            $this->connections[] = $socket;
        });
        $this->worker = new Worker();
        $this->binding = new ServerBinding($this->server, function (Throwable $error): void {
            $this->errors[] = $error;
        });
        $this->binding->bind($this->worker);

        return $this->connect();
    }

    private function connect(): SocketPairConnection
    {
        return new SocketPairConnection($this->worker);
    }

    private function get(SocketPairConnection $pair, string $target): string
    {
        $pair->write("GET $target HTTP/1.1\r\nHost: localhost\r\n\r\n");

        return $pair->read();
    }

    private function post(SocketPairConnection $pair, string $target, string $body): string
    {
        $pair->write("POST $target HTTP/1.1\r\nHost: localhost\r\nContent-Type: text/plain\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);

        return $pair->read();
    }

    /**
     * @return array{string, list<array{opcode: int, data: string}>}
     */
    private function openWebSocket(SocketPairConnection $pair, string $target): array
    {
        $pair->write("GET $target HTTP/1.1\r\n" . WebSocketFrames::upgradeHeaders() . "\r\n");

        return WebSocketFrames::afterHandshake($pair->read());
    }
}
