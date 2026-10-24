<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use InvalidArgumentException;
use Marcolin\SocketIO\Engine\Cors;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    public function testAllowsAnyOriginByDefault(): void
    {
        $cors = new Cors();

        self::assertSame(['Access-Control-Allow-Origin' => '*'], $cors->headers('https://a.test'));
        self::assertSame(['Access-Control-Allow-Origin' => '*'], $cors->headers(null));
    }

    public function testEchoesAListedOrigin(): void
    {
        $cors = new Cors(['https://a.test', 'https://b.test']);

        self::assertSame(
            ['Access-Control-Allow-Origin' => 'https://b.test', 'Vary' => 'Origin'],
            $cors->headers('https://b.test'),
        );
        self::assertSame(['Vary' => 'Origin'], $cors->headers('https://c.test'));
        self::assertSame(['Vary' => 'Origin'], $cors->headers(null));
    }

    public function testPreflightAllowsTheMethodsAndEchoesTheRequestedHeaders(): void
    {
        self::assertSame([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type, x-token',
            'Vary' => 'Access-Control-Request-Headers',
        ], (new Cors())->preflightHeaders('https://a.test', 'content-type, x-token'));

        self::assertSame([
            'Access-Control-Allow-Origin' => 'https://a.test',
            'Vary' => 'Origin, Access-Control-Request-Headers',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'content-type',
        ], (new Cors(['https://a.test']))->preflightHeaders('https://a.test', 'content-type'));

        self::assertSame([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        ], (new Cors())->preflightHeaders(null, null));
    }

    public function testRejectsAStringOtherThanStar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $cors = new Cors('https://a.test');
        self::fail('Accepted, with headers ' . json_encode($cors->headers(null)));
    }
}
