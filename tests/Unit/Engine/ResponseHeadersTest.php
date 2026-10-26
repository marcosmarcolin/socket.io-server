<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use InvalidArgumentException;
use Marcolin\SocketIO\Engine\ResponseHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseHeadersTest extends TestCase
{
    public function testKeepsTheGivenHeaders(): void
    {
        $headers = new ResponseHeaders(['Content-Type' => 'text/plain']);

        self::assertSame(['Content-Type' => 'text/plain'], $headers->all());
        self::assertSame('text/plain', $headers->get('content-type'));
        self::assertNull($headers->get('X-Missing'));
    }

    public function testSetReplacesWhateverTheCase(): void
    {
        $headers = new ResponseHeaders(['Cache-Control' => 'no-store']);

        $headers->set('cache-control', 'no-cache');

        self::assertSame(['cache-control' => 'no-cache'], $headers->all());
    }

    public function testAppendAddsALine(): void
    {
        $headers = new ResponseHeaders();

        $headers->append('Set-Cookie', 'a=1');
        $headers->append('set-cookie', 'b=2');

        self::assertSame(['Set-Cookie' => ['a=1', 'b=2']], $headers->all());
    }

    public function testRemove(): void
    {
        $headers = new ResponseHeaders(['X-A' => '1', 'X-B' => '2']);

        $headers->remove('x-a');
        $headers->remove('X-None');

        self::assertSame(['X-B' => '2'], $headers->all());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a name with a colon' => ['X-A:', 'v'];
        yield 'a name with a space' => ['X A', 'v'];
        yield 'an empty name' => ['', 'v'];
        yield 'a value with a line break' => ['X-A', "v\r\nInjected: 1"];
        yield 'a value with a newline' => ['X-A', "v\nx"];
        yield 'a value with a null byte' => ['X-A', "v\0"];
    }

    #[DataProvider('invalid')]
    public function testRefusesWhatWouldBreakTheResponse(string $name, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ResponseHeaders())->set($name, $value);
    }
}
