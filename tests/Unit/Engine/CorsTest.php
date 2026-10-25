<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use ArrayObject;
use InvalidArgumentException;
use Marcolin\SocketIO\Engine\Cors;
use Marcolin\SocketIO\Engine\Request;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    public function testAllowsAnyOriginByDefault(): void
    {
        $cors = new Cors();

        self::assertSame(['Access-Control-Allow-Origin' => '*'], $cors->headers(self::request('https://a.test')));
        self::assertSame(['Access-Control-Allow-Origin' => '*'], $cors->headers(self::request(null)));
    }

    public function testEchoesAListedOrigin(): void
    {
        $cors = new Cors(['https://a.test', 'https://b.test']);

        self::assertSame(
            ['Access-Control-Allow-Origin' => 'https://b.test', 'Vary' => 'Origin'],
            $cors->headers(self::request('https://b.test')),
        );
        self::assertSame(['Vary' => 'Origin'], $cors->headers(self::request('https://c.test')));
        self::assertSame(['Vary' => 'Origin'], $cors->headers(self::request(null)));
    }

    public function testAClosureDecidesForEachRequest(): void
    {
        $calls = new ArrayObject();
        // A common rule: the page's host, on any port.
        $sameHost = static function (string $origin, Request $request) use ($calls): bool {
            $calls->append([$origin, $request->header('Host')]);

            return parse_url($origin, PHP_URL_HOST) === parse_url('//' . $request->header('Host'), PHP_URL_HOST);
        };
        $cors = new Cors($sameHost, credentials: true);

        self::assertSame(
            [
                'Access-Control-Allow-Origin' => 'https://app.test',
                'Vary' => 'Origin',
                'Access-Control-Allow-Credentials' => 'true',
            ],
            $cors->headers(self::request('https://app.test', 'app.test:3000')),
        );
        self::assertSame(
            ['Vary' => 'Origin', 'Access-Control-Allow-Credentials' => 'true'],
            $cors->headers(self::request('https://evil.test', 'app.test:3000')),
        );
        self::assertSame(
            ['Vary' => 'Origin', 'Access-Control-Allow-Credentials' => 'true'],
            $cors->headers(self::request(null, 'app.test:3000')),
        );
        // Without an Origin header, there is nothing to decide.
        self::assertSame(
            [['https://app.test', 'app.test:3000'], ['https://evil.test', 'app.test:3000']],
            $calls->getArrayCopy(),
        );
    }

    public function testOnlyTrueFromTheClosureAllows(): void
    {
        $cors = new Cors(static fn (): bool => false);

        self::assertSame(['Vary' => 'Origin'], $cors->headers(self::request('https://a.test')));
    }

    public function testCredentialsWithAList(): void
    {
        $cors = new Cors(['https://a.test'], credentials: true);

        self::assertSame(
            [
                'Access-Control-Allow-Origin' => 'https://a.test',
                'Vary' => 'Origin',
                'Access-Control-Allow-Credentials' => 'true',
            ],
            $cors->headers(self::request('https://a.test')),
        );
        self::assertTrue($cors->credentials);
        self::assertFalse((new Cors())->credentials);
    }

    public function testPreflightAllowsTheMethodsAndEchoesTheRequestedHeaders(): void
    {
        self::assertSame([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type, x-token',
            'Vary' => 'Access-Control-Request-Headers',
        ], (new Cors())->preflightHeaders(self::request('https://a.test', null, 'content-type, x-token')));

        self::assertSame([
            'Access-Control-Allow-Origin' => 'https://a.test',
            'Vary' => 'Origin, Access-Control-Request-Headers',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type',
        ], (new Cors(['https://a.test'], true))->preflightHeaders(
            self::request('https://a.test', null, 'content-type'),
        ));

        self::assertSame([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ], (new Cors())->preflightHeaders(self::request(null)));
    }

    public function testRejectsAStringOtherThanStar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $cors = new Cors('https://a.test');
        self::fail('Accepted, with headers ' . json_encode($cors->headers(self::request(null))));
    }

    public function testRejectsStarWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('credentials');

        $cors = new Cors('*', credentials: true);
        self::fail('Accepted, with headers ' . json_encode($cors->headers(self::request(null))));
    }

    private static function request(?string $origin, ?string $host = null, ?string $requestHeaders = null): Request
    {
        $headers = array_filter(
            ['Origin' => $origin, 'Host' => $host, 'Access-Control-Request-Headers' => $requestHeaders],
            static fn (?string $value): bool => $value !== null,
        );

        return new Request('/socket.io/', [], $headers);
    }
}
