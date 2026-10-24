<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Workerman;

use ArrayObject;
use Marcolin\SocketIO\Engine\Workerman\WorkermanHttpExchange;
use Marcolin\SocketIO\Tests\Fake\SocketPairConnection;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

final class WorkermanHttpExchangeTest extends TestCase
{
    private SocketPairConnection $pair;

    protected function setUp(): void
    {
        $worker = new Worker();
        $worker->protocol = Http::class;
        $this->pair = new SocketPairConnection($worker);
    }

    public function testCopiesTheRequest(): void
    {
        $exchange = $this->exchange(
            "POST /engine.io/?EIO=4&sid=abc&list[]=x HTTP/1.1\r\nHost: localhost\r\nOrigin: https://a.test\r\n"
            . "Content-Length: 6\r\n\r\n4hello",
        );

        self::assertSame('POST', $exchange->method());
        self::assertSame('/engine.io/', $exchange->path());
        self::assertSame('abc', $exchange->query('sid'));
        self::assertNull($exchange->query('list'));
        self::assertNull($exchange->query('missing'));
        self::assertSame('https://a.test', $exchange->header('ORIGIN'));
        self::assertNull($exchange->header('X-Missing'));
        self::assertSame('4hello', $exchange->body());
    }

    public function testAnswersOnce(): void
    {
        $exchange = $this->exchange("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $exchange->respond(200, 'first', ['Content-Type' => 'text/plain']);
        $exchange->respond(400, 'second');

        $response = $this->pair->read();
        self::assertTrue($exchange->isAnswered());
        self::assertStringStartsWith('HTTP/1.1 200 OK', $response);
        self::assertStringEndsWith("\r\n\r\nfirst", $response);
    }

    public function testAbortsOnlyAnUnansweredRequest(): void
    {
        $aborts = new ArrayObject();
        $pending = $this->exchange("GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $answered = $this->exchange("GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $pending->onAbort(static function () use ($aborts): void {
            $aborts->append('pending');
        });
        $answered->onAbort(static function () use ($aborts): void {
            $aborts->append('answered');
        });
        $answered->respond(200, '');

        $pending->abort();
        $answered->abort();

        self::assertSame(['pending'], $aborts->getArrayCopy());
    }

    private function exchange(string $raw): WorkermanHttpExchange
    {
        return new WorkermanHttpExchange($this->pair->connection, new Request($raw));
    }
}
