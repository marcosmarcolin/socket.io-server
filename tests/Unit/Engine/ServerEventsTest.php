<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\ResponseHeaders;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The server's `initial_headers`, `headers` and `connection_error` events, as the reference's.
 */
final class ServerEventsTest extends TestCase
{
    private const HANDSHAKE = '/engine.io/?EIO=4&transport=polling';
    private const POLL = '/engine.io/?EIO=4&transport=polling&sid=sid1';

    private Server $server;

    /** @var list<array{string, ?string}> each event and the sid of its request */
    private array $log = [];

    protected function setUp(): void
    {
        $this->server = new Server(
            new ServerOptions(path: '/engine.io/', cors: null),
            new FakeScheduler(),
            new SequentialSessionIdGenerator(),
        );
        foreach (['initial_headers', 'headers'] as $event) {
            $this->server->on($event, function (ResponseHeaders $headers, Request $request) use ($event): void {
                $this->log[] = [$event, $request->query('sid')];
            });
        }
    }

    public function testTheHandshakeGetsInitialHeadersThenHeaders(): void
    {
        $this->request('GET', self::HANDSHAKE);

        self::assertSame([['initial_headers', null], ['headers', null]], $this->log);
    }

    public function testLaterPollsAndPostsGetOnlyHeadersWhenAnswered(): void
    {
        $this->request('GET', self::HANDSHAKE);
        $this->log = [];

        $this->request('POST', self::POLL, '4hello');
        self::assertSame([['headers', 'sid1']], $this->log);

        // A poll waits for something to send, and its headers go out with it.
        $poll = $this->request('GET', self::POLL);
        self::assertNull($poll->status);
        self::assertCount(1, $this->log);
        $this->server->session('sid1')?->send('news');

        self::assertSame(200, $poll->status);
        self::assertSame([['headers', 'sid1'], ['headers', 'sid1']], $this->log);
    }

    public function testListenersChangeTheResponseHeaders(): void
    {
        $this->server->on('initial_headers', static function (ResponseHeaders $headers): void {
            $headers->append('Set-Cookie', 'io=sid1; Path=/; HttpOnly');
            $headers->append('Set-Cookie', 'lang=pt');
        });
        $this->server->on('headers', static function (ResponseHeaders $headers): void {
            $headers->set('X-Served-By', 'php');
        });

        $handshake = $this->request('GET', self::HANDSHAKE);
        $poll = $this->request('POST', self::POLL, '4hi');

        self::assertSame(['io=sid1; Path=/; HttpOnly', 'lang=pt'], $handshake->responseHeaders['Set-Cookie'] ?? null);
        self::assertSame('php', $handshake->responseHeaders['X-Served-By'] ?? null);
        self::assertArrayNotHasKey('Set-Cookie', $poll->responseHeaders);
        self::assertSame('php', $poll->responseHeaders['X-Served-By'] ?? null);
    }

    public function testErrorsAndPreflightsGetNoHeadersEvent(): void
    {
        $server = new Server(new ServerOptions(path: '/engine.io/'), new FakeScheduler());
        $calls = 0;
        $server->on('headers', static function () use (&$calls): void {
            ++$calls;
        });

        $server->handleRequest(FakeHttpExchange::fromUrl('GET', '/engine.io/?EIO=4&transport=nope'));
        $server->handleRequest(FakeHttpExchange::fromUrl('OPTIONS', self::HANDSHAKE));
        $server->handleRequest(FakeHttpExchange::fromUrl('GET', '/elsewhere/'));

        self::assertSame(0, $calls);
    }

    public function testAWebSocketGetsTheHeadersOfIts101(): void
    {
        $this->server->on('initial_headers', static function (ResponseHeaders $headers): void {
            $headers->set('Set-Cookie', 'io=new');
        });
        $this->server->on('headers', static function (ResponseHeaders $headers): void {
            $headers->set('X-Served-By', 'php');
        });

        $new = $this->server->webSocketHeaders($this->webSocket('/engine.io/?EIO=4&transport=websocket'));
        $upgrade = $this->server->webSocketHeaders($this->webSocket('/engine.io/?EIO=4&transport=websocket&sid=x'));

        self::assertSame(['Set-Cookie' => 'io=new', 'X-Served-By' => 'php'], $new);
        self::assertSame(['X-Served-By' => 'php'], $upgrade);
    }

    public function testARefusedRequestEmitsConnectionError(): void
    {
        $errors = [];
        $this->server->on(
            'connection_error',
            static function (Request $request, int $code, string $message) use (&$errors): void {
                $errors[] = [$request->query('transport'), $code, $message];
            },
        );

        $this->request('GET', '/engine.io/?EIO=4&transport=nope');
        $this->request('GET', '/engine.io/?EIO=4&transport=polling&sid=unknown');
        $this->server->verifyWebSocket($this->webSocket('/engine.io/?EIO=3&transport=websocket'));

        self::assertSame([
            ['nope', 0, 'Transport unknown'],
            ['polling', 1, 'Session ID unknown'],
            ['websocket', 5, 'Unsupported protocol version'],
        ], $errors);
    }

    public function testConnectionErrorCarriesTheAllowRequestMessage(): void
    {
        $server = new Server(
            new ServerOptions(path: '/engine.io/', allowRequest: static fn (): string => 'invalid session'),
            new FakeScheduler(),
        );
        $errors = [];
        $server->on(
            'connection_error',
            static function (Request $request, int $code, string $message) use (&$errors): void {
                $errors[] = [$code, $message];
            },
        );

        $server->handleRequest(FakeHttpExchange::fromUrl('GET', self::HANDSHAKE));

        self::assertSame([[4, 'invalid session']], $errors);
    }

    private function request(string $method, string $url, string $body = ''): FakeHttpExchange
    {
        $exchange = FakeHttpExchange::fromUrl($method, $url, $body);
        $this->server->handleRequest($exchange);

        return $exchange;
    }

    private function webSocket(string $url): Request
    {
        return new Request((string) parse_url($url, PHP_URL_PATH), FakeHttpExchange::parseQuery($url));
    }
}
