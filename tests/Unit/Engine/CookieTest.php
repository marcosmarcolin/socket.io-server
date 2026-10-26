<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use InvalidArgumentException;
use Marcolin\SocketIO\Engine\Cookie;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\ResponseHeaders;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `cookie` option: the session id on the polling handshake, for sticky sessions, as in the reference.
 */
final class CookieTest extends TestCase
{
    private const HANDSHAKE = '/engine.io/?EIO=4&transport=polling';

    public function testTheDefaultsAreTheReferences(): void
    {
        self::assertSame('io=abc; Path=/; HttpOnly; SameSite=Lax', (new Cookie())->header('abc'));
    }

    public function testEveryAttributeInTheReferencesOrder(): void
    {
        $cookie = Cookie::fromOption([
            'name' => 'sticky',
            'path' => '/app',
            'domain' => 'example.com',
            'httpOnly' => false,
            'secure' => true,
            'sameSite' => 'none',
            'maxAge' => 3600,
        ]);

        self::assertSame(
            'sticky=a%2Fb; Max-Age=3600; Domain=example.com; Path=/app; Secure; SameSite=None',
            $cookie?->header('a/b'),
        );
    }

    public function testAttributesCanBeLeftOut(): void
    {
        $cookie = Cookie::fromOption(['path' => null, 'httpOnly' => false, 'sameSite' => null]);

        self::assertSame('io=abc', $cookie?->header('abc'));
    }

    public function testTheOptionIsOffByDefault(): void
    {
        self::assertNull(Cookie::fromOption(false));
        self::assertNull(ServerOptions::fromArray([])->cookie);
        self::assertSame('io', ServerOptions::fromArray(['cookie' => true])->cookie?->name);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'a string' => ['io'];
        yield 'an unknown key' => [['expires' => 1]];
        yield 'a name that is not a token' => [['name' => 'a b']];
        yield 'a path with a semicolon' => [['path' => '/;x']];
        yield 'a domain with a line break' => [['domain' => "a\r\nb"]];
        yield 'an unknown sameSite' => [['sameSite' => 'sometimes']];
        yield 'httpOnly not a boolean' => [['httpOnly' => 'yes']];
        yield 'maxAge not an integer' => [['maxAge' => '60']];
    }

    #[DataProvider('invalid')]
    public function testRefusesInvalidOptions(mixed $option): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cookie::fromOption($option);
    }

    public function testTheHandshakeSetsTheCookieBeforeInitialHeaders(): void
    {
        $server = $this->server();
        $seen = null;
        $server->on('initial_headers', static function (ResponseHeaders $headers) use (&$seen): void {
            $seen = $headers->get('Set-Cookie');
            $headers->append('Set-Cookie', 'other=1');
        });

        $handshake = $this->request($server, self::HANDSHAKE);
        $poll = $this->request($server, '/engine.io/?EIO=4&transport=polling&sid=sid1', 'POST', '4hi');

        self::assertSame(['io=sid1; Path=/; HttpOnly; SameSite=Lax'], $seen);
        self::assertSame(
            ['io=sid1; Path=/; HttpOnly; SameSite=Lax', 'other=1'],
            $handshake->responseHeaders['Set-Cookie'] ?? null,
        );
        self::assertArrayNotHasKey('Set-Cookie', $poll->responseHeaders);
    }

    public function testAWebSocketGetsNoCookie(): void
    {
        $request = new Request('/engine.io/', ['EIO' => '4', 'transport' => 'websocket']);

        self::assertSame([], $this->server()->webSocketHeaders($request));
    }

    private function server(): Server
    {
        return new Server(
            new ServerOptions(path: '/engine.io/', cors: null, cookie: new Cookie()),
            new FakeScheduler(),
            new SequentialSessionIdGenerator(),
        );
    }

    private function request(Server $server, string $url, string $method = 'GET', string $body = ''): FakeHttpExchange
    {
        $exchange = FakeHttpExchange::fromUrl($method, $url, $body);
        $server->handleRequest($exchange);

        return $exchange;
    }
}
