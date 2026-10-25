<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Closure;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Cors;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerError;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Socket;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\FakeWebSocketConnection;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Includes the official Engine.IO v4 suite scenarios that depend on the server, with its options.
 */
final class ServerTest extends TestCase
{
    private const PING_INTERVAL = 300;
    private const PING_TIMEOUT = 200;
    private const HANDSHAKE = '/engine.io/?EIO=4&transport=polling';
    private const UPGRADE = '/engine.io/?EIO=4&transport=websocket&sid=sid1';

    private FakeScheduler $scheduler;

    private Server $server;

    /** @var list<Socket> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->server = $this->server(new ServerOptions(
            pingInterval: self::PING_INTERVAL,
            pingTimeout: self::PING_TIMEOUT,
            path: '/engine.io/',
        ));
    }

    // Handshake

    public function testPollingHandshake(): void
    {
        $get = $this->request('GET', self::HANDSHAKE);

        self::assertSame(200, $get->status);
        $open = json_decode(substr((string) $get->responseBody, 1), true);
        self::assertIsArray($open);
        self::assertSame('sid1', $open['sid']);
        self::assertSame(['websocket'], $open['upgrades']);
        self::assertSame(self::PING_INTERVAL, $open['pingInterval']);
        self::assertSame(self::PING_TIMEOUT, $open['pingTimeout']);
        self::assertSame(1_000_000, $open['maxPayload']);
        self::assertCount(1, $this->connections);
        self::assertSame('sid1', $this->connections[0]->id);
        self::assertSame($this->connections[0], $this->server->session('sid1'));
        self::assertSame(1, $this->server->sessionCount());
    }

    /**
     * @return iterable<string, array{string, string, ServerError}>
     */
    public static function invalidHandshakes(): iterable
    {
        yield 'no EIO' => ['GET', '/engine.io/?transport=polling', ServerError::UnsupportedProtocolVersion];
        yield 'EIO=abc' => ['GET', '/engine.io/?EIO=abc&transport=polling', ServerError::UnsupportedProtocolVersion];
        yield 'EIO=3' => ['GET', '/engine.io/?EIO=3&transport=polling', ServerError::UnsupportedProtocolVersion];
        yield 'no transport' => ['GET', '/engine.io/?EIO=4', ServerError::UnknownTransport];
        yield 'transport=abc' => ['GET', '/engine.io/?EIO=4&transport=abc', ServerError::UnknownTransport];
        yield 'POST' => ['POST', '/engine.io/?EIO=4&transport=polling', ServerError::BadHandshakeMethod];
        yield 'PUT' => ['PUT', '/engine.io/?EIO=4&transport=polling', ServerError::BadHandshakeMethod];
        yield 'websocket over HTTP' => ['GET', '/engine.io/?EIO=4&transport=websocket', ServerError::BadRequest];
        yield 'unknown sid' => ['GET', '/engine.io/?EIO=4&transport=polling&sid=nope', ServerError::UnknownSid];
    }

    #[DataProvider('invalidHandshakes')]
    public function testRejectsInvalidHandshakes(string $method, string $url, ServerError $error): void
    {
        $exchange = $this->request($method, $url);

        $this->assertRejected($exchange, $error);
        self::assertSame([], $this->connections);
        self::assertSame(0, $this->server->sessionCount());
    }

    public function testRejectsAnOriginWithInvalidCharacters(): void
    {
        $exchange = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => "a\nb"]);

        $this->assertRejected($exchange, ServerError::BadRequest);
    }

    public function testAnswersOtherPathsWith404(): void
    {
        $exchange = $this->request('GET', '/other/?EIO=4&transport=polling');

        self::assertSame(404, $exchange->status);
        self::assertSame([], $this->connections);
    }

    public function testMatchesThePathWithOrWithoutTheTrailingSlash(): void
    {
        self::assertTrue($this->server->handles('/engine.io/'));
        self::assertTrue($this->server->handles('/engine.io'));
        self::assertFalse($this->server->handles('/engine.iox'));
        self::assertFalse($this->server->handles('/'));
    }

    // Sessions

    public function testRoutesRequestsWithASidToTheSession(): void
    {
        $this->handshake();
        $messages = [];
        $this->connections[0]->on('message', function (string|Binary $data) use (&$messages): void {
            $messages[] = $data;
        });

        $post = $this->request('POST', '/engine.io/?EIO=4&transport=polling&sid=sid1', "4hello\x1e4world");
        $this->connections[0]->send('back');
        $get = $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=sid1');

        self::assertSame('ok', $post->responseBody);
        self::assertSame(['hello', 'world'], $messages);
        self::assertSame('4back', $get->responseBody);
    }

    public function testRejectsASessionRequestWithoutTheProtocolVersion(): void
    {
        $this->handshake();

        $this->assertRejected($this->request('GET', '/engine.io/?transport=polling&sid=sid1'), ServerError::BadRequest);
    }

    public function testRejectsAWebSocketTransportOverHttpForAPollingSession(): void
    {
        $this->handshake();

        $this->assertRejected(
            $this->request('GET', '/engine.io/?EIO=4&transport=websocket&sid=sid1'),
            ServerError::BadRequest,
        );
    }

    public function testATooLargePostIsRefusedWithoutClosingTheSession(): void
    {
        $this->server = $this->server(new ServerOptions(maxPayload: 10, path: '/engine.io/'));
        $this->handshake();

        $post = $this->request('POST', '/engine.io/?EIO=4&transport=polling&sid=sid1', '4' . str_repeat('a', 10));

        self::assertSame(413, $post->status);
        self::assertNotNull($this->server->session('sid1'));
    }

    public function testCloseClosesEverySession(): void
    {
        $this->handshake();
        $this->handshake();
        $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=sid1');
        $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=sid2');

        $this->server->close();

        self::assertSame(0, $this->server->sessionCount());
    }

    // Suite scenarios that end with "the next poll gets 400"

    public function testAnInvalidPayloadClosesTheSession(): void
    {
        $this->handshake();

        $post = $this->request('POST', '/engine.io/?EIO=4&transport=polling&sid=sid1', 'abc');

        self::assertSame(400, $post->status);
        $this->assertRejected($this->poll(), ServerError::UnknownSid);
    }

    public function testDuplicatePollsCloseTheSession(): void
    {
        $this->handshake();
        $first = $this->poll();

        $second = $this->poll();

        self::assertSame('1', $first->responseBody);
        self::assertSame(400, $second->status);
        $this->assertRejected($this->poll(), ServerError::UnknownSid);
    }

    public function testACancelledPollClosesTheSession(): void
    {
        $this->handshake();

        $this->poll()->abort();

        $this->assertRejected($this->poll(), ServerError::UnknownSid);
    }

    public function testThePingTimeoutClosesTheSession(): void
    {
        $this->handshake();

        $this->scheduler->advance(self::PING_INTERVAL + self::PING_TIMEOUT);

        $this->assertRejected($this->poll(), ServerError::UnknownSid);
    }

    public function testAClosePacketClosesTheSession(): void
    {
        $this->handshake();
        $poll = $this->poll();

        $this->request('POST', '/engine.io/?EIO=4&transport=polling&sid=sid1', '1');

        self::assertSame('6', $poll->responseBody);
        $this->assertRejected($this->poll(), ServerError::UnknownSid);
    }

    // CORS

    public function testAddsCorsHeadersToEveryAnswerByDefault(): void
    {
        $handshake = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => 'https://a.test']);
        $poll = $this->poll();
        $this->connections[0]->send('later');
        $error = $this->request('GET', '/engine.io/?EIO=4');

        self::assertSame('*', $handshake->responseHeaders['Access-Control-Allow-Origin'] ?? null);
        self::assertSame('*', $poll->responseHeaders['Access-Control-Allow-Origin'] ?? null);
        self::assertSame('*', $error->responseHeaders['Access-Control-Allow-Origin'] ?? null);
    }

    public function testAnswersThePreflight(): void
    {
        $preflight = $this->request('OPTIONS', self::HANDSHAKE, headers: [
            'Origin' => 'https://a.test',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        self::assertSame(204, $preflight->status);
        self::assertSame('*', $preflight->responseHeaders['Access-Control-Allow-Origin'] ?? null);
        self::assertSame('GET, POST, OPTIONS', $preflight->responseHeaders['Access-Control-Allow-Methods'] ?? null);
        self::assertSame('content-type', $preflight->responseHeaders['Access-Control-Allow-Headers'] ?? null);
        self::assertSame([], $this->connections);
    }

    public function testOnlyListedOriginsAreAllowed(): void
    {
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', cors: new Cors(['https://a.test'])));

        $allowed = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => 'https://a.test']);
        $other = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => 'https://b.test']);

        self::assertSame('https://a.test', $allowed->responseHeaders['Access-Control-Allow-Origin'] ?? null);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $other->responseHeaders);
        self::assertSame('Origin', $other->responseHeaders['Vary'] ?? null);
    }

    public function testCredentialsWithAnOriginDecidedPerRequest(): void
    {
        $sameHost = static fn (string $origin, Request $request): bool =>
            parse_url($origin, PHP_URL_HOST) === parse_url('//' . $request->header('Host'), PHP_URL_HOST);
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', cors: new Cors($sameHost, true)));
        $page = ['Origin' => 'https://app.test', 'Host' => 'app.test:3000'];

        $handshake = $this->request('GET', self::HANDSHAKE, headers: $page);
        $poll = $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=sid1', headers: $page);
        $this->connections[0]->send('later');
        $error = $this->request('GET', '/engine.io/?EIO=4', headers: $page);
        $preflight = $this->request('OPTIONS', self::HANDSHAKE, headers: $page);
        $foreign = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => 'https://evil.test'] + $page);

        foreach ([$handshake, $poll, $error, $preflight] as $exchange) {
            self::assertSame('https://app.test', $exchange->responseHeaders['Access-Control-Allow-Origin'] ?? null);
            self::assertSame('true', $exchange->responseHeaders['Access-Control-Allow-Credentials'] ?? null);
        }
        self::assertSame(204, $preflight->status);
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $foreign->responseHeaders);
    }

    public function testCorsCanBeDisabled(): void
    {
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', cors: null));

        $get = $this->request('GET', self::HANDSHAKE, headers: ['Origin' => 'https://a.test']);
        $preflight = $this->request('OPTIONS', self::HANDSHAKE);

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $get->responseHeaders);
        $this->assertRejected($preflight, ServerError::BadHandshakeMethod);
    }

    // The transports option

    public function testWebSocketOnlyRefusesPollingAsAnUnknownTransport(): void
    {
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', transports: ['websocket']));
        $connection = new FakeWebSocketConnection();

        $this->assertRejected($this->request('GET', self::HANDSHAKE), ServerError::UnknownTransport);
        $this->server->handleWebSocket($this->webSocketRequest('/engine.io/?EIO=4&transport=websocket'), $connection);

        $open = json_decode(substr($connection->sentData()[0] ?? '', 1), true);
        self::assertIsArray($open);
        self::assertSame([], $open['upgrades']);
    }

    // The allowUpgrades option

    public function testWithoutUpgradesThePollingHandshakeOffersNone(): void
    {
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', allowUpgrades: false));

        $handshake = $this->request('GET', self::HANDSHAKE);

        $open = json_decode(substr((string) $handshake->responseBody, 1), true);
        self::assertIsArray($open);
        self::assertSame([], $open['upgrades']);
    }

    public function testWithoutUpgradesAClientThatTriesAnywayStillUpgrades(): void
    {
        // The option only changes what the handshake offers, as in the reference.
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', allowUpgrades: false));
        $this->handshake();
        $connection = new FakeWebSocketConnection();
        $request = $this->webSocketRequest(self::UPGRADE);

        self::assertNull($this->server->verifyWebSocket($request));
        $this->server->handleWebSocket($request, $connection);
        $connection->receive('2probe');
        $this->poll();
        $connection->receive('5');

        self::assertTrue($this->connections[0]->isUpgraded());
    }

    public function testPollingOnlyOffersNoUpgradeAndRefusesWebSockets(): void
    {
        $this->server = $this->server(new ServerOptions(path: '/engine.io/', transports: ['polling']));

        $handshake = $this->request('GET', self::HANDSHAKE);

        $open = json_decode(substr((string) $handshake->responseBody, 1), true);
        self::assertIsArray($open);
        self::assertSame([], $open['upgrades']);
        self::assertSame(
            ServerError::UnknownTransport,
            $this->server->verifyWebSocket($this->webSocketRequest('/engine.io/?EIO=4&transport=websocket'))?->error,
        );
        self::assertSame(
            ServerError::UnknownTransport,
            $this->server->verifyWebSocket($this->webSocketRequest(self::UPGRADE))?->error,
        );
        $connection = new FakeWebSocketConnection();
        $this->server->handleWebSocket($this->webSocketRequest(self::UPGRADE), $connection);
        self::assertTrue($connection->closed);
        self::assertFalse($this->connections[0]->isUpgrading());
    }

    // The allowRequest option

    public function testAllowRequestLetsAHandshakeIn(): void
    {
        $seen = [];
        $this->server = $this->allowing(static function (Request $request) use (&$seen): bool {
            $seen[] = $request->header('Cookie');

            return true;
        });

        $handshake = $this->request('GET', self::HANDSHAKE, headers: ['Cookie' => 'session=abc']);

        self::assertSame(200, $handshake->status);
        self::assertSame(['session=abc'], $seen);
        self::assertCount(1, $this->connections);
    }

    public function testAllowRequestRefusesWithForbidden(): void
    {
        $this->server = $this->allowing(static fn (): bool => false);

        $handshake = $this->request('GET', self::HANDSHAKE);

        $this->assertForbidden($handshake, 'Forbidden');
        self::assertSame([], $this->connections);
        self::assertSame(0, $this->server->sessionCount());
    }

    public function testAllowRequestRefusesWithItsMessage(): void
    {
        $this->server = $this->allowing(static fn (): string => 'Sessão inválida');

        $this->assertForbidden($this->request('GET', self::HANDSHAKE), 'Sessão inválida');
    }

    public function testAnEmptyMessageRefusesWithTheDefaultOne(): void
    {
        $this->server = $this->allowing(static fn (): string => '');

        $this->assertForbidden($this->request('GET', self::HANDSHAKE), 'Forbidden');
    }

    public function testAllowRequestRunsOnlyOnHandshakes(): void
    {
        $calls = 0;
        $this->server = $this->allowing(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        $this->handshake();
        $this->request('POST', '/engine.io/?EIO=4&transport=polling&sid=sid1', '4hello');
        $connection = new FakeWebSocketConnection();
        $upgrade = $this->webSocketRequest(self::UPGRADE);
        self::assertNull($this->server->verifyWebSocket($upgrade));
        $this->server->handleWebSocket($upgrade, $connection);

        self::assertSame(1, $calls);
    }

    public function testAllowRequestRunsBeforeTheProtocolCheck(): void
    {
        $this->server = $this->allowing(static fn (): bool => false);

        $this->assertForbidden($this->request('GET', '/engine.io/?transport=polling'), 'Forbidden');
    }

    public function testAllowRequestRunsAfterTheMethodAndTransportChecks(): void
    {
        $calls = 0;
        $this->server = $this->allowing(static function () use (&$calls): bool {
            ++$calls;

            return false;
        });

        $this->assertRejected($this->request('POST', self::HANDSHAKE), ServerError::BadHandshakeMethod);
        $this->assertRejected($this->request('GET', '/engine.io/?EIO=4'), ServerError::UnknownTransport);
        $this->assertRejected($this->request('GET', '/engine.io/?EIO=4&transport=websocket'), ServerError::BadRequest);
        self::assertSame(0, $calls);
    }

    public function testAllowRequestRefusesAWebSocketHandshakeOnce(): void
    {
        $calls = 0;
        $this->server = $this->allowing(static function () use (&$calls): string {
            ++$calls;

            return 'no cookie';
        });
        $request = $this->webSocketRequest('/engine.io/?EIO=4&transport=websocket');

        $rejection = $this->server->verifyWebSocket($request);

        self::assertSame(ServerError::Forbidden, $rejection?->error);
        self::assertSame('no cookie', $rejection->message);
        self::assertSame(1, $calls);
    }

    public function testAllowRequestIsAskedOnceForAnAcceptedWebSocket(): void
    {
        $calls = 0;
        $this->server = $this->allowing(static function () use (&$calls): bool {
            return ++$calls === 1;
        });
        $request = $this->webSocketRequest('/engine.io/?EIO=4&transport=websocket');
        $connection = new FakeWebSocketConnection();

        self::assertNull($this->server->verifyWebSocket($request));
        $this->server->handleWebSocket($request, $connection);

        self::assertSame(1, $calls);
        self::assertFalse($connection->closed);
        self::assertCount(1, $this->connections);
    }

    public function testAnExceptionFromAllowRequestPropagates(): void
    {
        $this->server = $this->allowing(static function (): bool {
            throw new RuntimeException('database down');
        });

        $this->expectExceptionMessage('database down');

        $this->request('GET', self::HANDSHAKE);
    }

    // WebSocket

    public function testWebSocketHandshake(): void
    {
        $request = $this->webSocketRequest('/engine.io/?EIO=4&transport=websocket');
        $connection = new FakeWebSocketConnection();

        self::assertNull($this->server->verifyWebSocket($request));
        $this->server->handleWebSocket($request, $connection);

        self::assertCount(1, $this->connections);
        $open = json_decode(substr($connection->sentData()[0], 1), true);
        self::assertIsArray($open);
        self::assertSame('sid1', $open['sid']);
        self::assertSame([], $open['upgrades']);
    }

    /**
     * @return iterable<string, array{string, ServerError}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'no EIO' => ['/engine.io/?transport=websocket', ServerError::UnsupportedProtocolVersion];
        yield 'EIO=abc' => ['/engine.io/?EIO=abc&transport=websocket', ServerError::UnsupportedProtocolVersion];
        yield 'no transport' => ['/engine.io/?EIO=4', ServerError::UnknownTransport];
        yield 'transport=abc' => ['/engine.io/?EIO=4&transport=abc', ServerError::UnknownTransport];
        yield 'polling' => ['/engine.io/?EIO=4&transport=polling', ServerError::BadRequest];
        yield 'unknown sid' => ['/engine.io/?EIO=4&transport=websocket&sid=nope', ServerError::UnknownSid];
        yield 'other path' => ['/other/?EIO=4&transport=websocket', ServerError::BadRequest];
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequests(string $url, ServerError $error): void
    {
        $request = $this->webSocketRequest($url);
        $connection = new FakeWebSocketConnection();

        self::assertSame($error, $this->server->verifyWebSocket($request)?->error);
        $this->server->handleWebSocket($request, $connection);

        self::assertTrue($connection->closed);
        self::assertSame([], $connection->sent);
        self::assertSame([], $this->connections);
    }

    public function testUpgradesAPollingSession(): void
    {
        $this->handshake();
        $connection = new FakeWebSocketConnection();
        $request = $this->webSocketRequest(self::UPGRADE);

        self::assertNull($this->server->verifyWebSocket($request));
        $this->server->handleWebSocket($request, $connection);
        $connection->receive('2probe');
        $noop = $this->poll();
        $connection->receive('5');

        self::assertSame(['3probe'], $connection->sentData());
        self::assertSame('6', $noop->responseBody);
        self::assertTrue($this->connections[0]->isUpgraded());
        self::assertCount(1, $this->connections);
    }

    public function testRejectsHttpRequestsAfterTheUpgrade(): void
    {
        $connection = $this->upgradedSession();

        $this->assertRejected($this->poll(), ServerError::BadRequest);
        $connection->receive('4hello');
        self::assertSame(1, $this->server->sessionCount());
    }

    public function testClosesAnotherWebSocketAfterTheUpgrade(): void
    {
        $first = $this->upgradedSession();
        $second = new FakeWebSocketConnection();

        $this->server->handleWebSocket($this->webSocketRequest(self::UPGRADE), $second);

        self::assertTrue($second->closed);
        self::assertFalse($first->closed);
    }

    public function testClosesAWebSocketWhoseSessionClosedAfterVerification(): void
    {
        $this->handshake();
        $request = $this->webSocketRequest(self::UPGRADE);
        self::assertNull($this->server->verifyWebSocket($request));
        $this->connections[0]->close();
        $connection = new FakeWebSocketConnection();

        $this->server->handleWebSocket($request, $connection);

        self::assertTrue($connection->closed);
    }

    // Helpers

    private function server(ServerOptions $options): Server
    {
        $this->connections = [];
        $server = new Server($options, $this->scheduler, new SequentialSessionIdGenerator());
        $server->on('connection', function (Socket $socket): void {
            $this->connections[] = $socket;
        });

        return $server;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, string $body = '', array $headers = []): FakeHttpExchange
    {
        $exchange = FakeHttpExchange::fromUrl($method, $url, $body, $headers);
        $this->server->handleRequest($exchange);

        return $exchange;
    }

    /**
     * Opens a polling session and consumes the open packet.
     */
    private function handshake(): void
    {
        self::assertSame(200, $this->request('GET', self::HANDSHAKE)->status);
    }

    private function poll(string $sid = 'sid1'): FakeHttpExchange
    {
        return $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=' . $sid);
    }

    private function upgradedSession(): FakeWebSocketConnection
    {
        $this->handshake();
        $connection = new FakeWebSocketConnection();
        $this->server->handleWebSocket($this->webSocketRequest(self::UPGRADE), $connection);
        $connection->receive('2probe');
        $connection->receive('5');
        self::assertTrue($this->connections[0]->isUpgraded());

        return $connection;
    }

    private function webSocketRequest(string $url): Request
    {
        return new Request((string) parse_url($url, PHP_URL_PATH), FakeHttpExchange::parseQuery($url));
    }

    /**
     * @param Closure(Request): (bool|string) $allowRequest
     */
    private function allowing(Closure $allowRequest): Server
    {
        return $this->server(new ServerOptions(path: '/engine.io/', allowRequest: $allowRequest));
    }

    private function assertForbidden(FakeHttpExchange $exchange, string $message): void
    {
        self::assertSame(403, $exchange->status);
        self::assertSame(['code' => 4, 'message' => $message], json_decode((string) $exchange->responseBody, true));
        self::assertSame('application/json', $exchange->responseHeaders['Content-Type'] ?? null);
    }

    private function assertRejected(FakeHttpExchange $exchange, ServerError $error): void
    {
        self::assertSame(400, $exchange->status);
        self::assertSame(
            ['code' => $error->value, 'message' => $error->message()],
            json_decode((string) $exchange->responseBody, true),
        );
        self::assertSame('application/json', $exchange->responseHeaders['Content-Type'] ?? null);
    }
}
