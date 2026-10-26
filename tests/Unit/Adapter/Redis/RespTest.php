<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use Marcolin\SocketIO\Adapter\Redis\RedisError;
use Marcolin\SocketIO\Adapter\Redis\Resp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class RespTest extends TestCase
{
    public function testEncodesACommandAsBulkStrings(): void
    {
        self::assertSame(
            "*3\r\n\$7\r\nPUBLISH\r\n\$2\r\nch\r\n\$4\r\na\r\nb\r\n",
            Resp::command('PUBLISH', 'ch', "a\r\nb"),
        );
    }

    public function testEncodesBinaryAndEmptyArguments(): void
    {
        self::assertSame("*2\r\n\$2\r\n\x00\xff\r\n\$0\r\n\r\n", Resp::command("\x00\xff", ''));
    }

    public function testDecodesEveryReplyType(): void
    {
        $resp = new Resp();

        $replies = $resp->add("+OK\r\n-ERR wrong\r\n:42\r\n:-1\r\n\$3\r\nabc\r\n\$-1\r\n*-1\r\n*0\r\n");

        self::assertEquals(['OK', new RedisError('ERR wrong'), 42, -1, 'abc', null, null, []], $replies);
    }

    public function testDecodesNestedArrays(): void
    {
        $resp = new Resp();

        self::assertSame(
            [['message', 'ch', "x\r\ny"], [1, ['a']]],
            $resp->add("*3\r\n\$7\r\nmessage\r\n\$2\r\nch\r\n\$4\r\nx\r\ny\r\n*2\r\n:1\r\n*1\r\n+a\r\n"),
        );
    }

    public function testKeepsAPartialReplyUntilItCompletes(): void
    {
        $resp = new Resp();
        $bytes = "*3\r\n\$9\r\nsubscribe\r\n\$2\r\nch\r\n:1\r\n+OK\r\n";
        $replies = [];

        foreach (str_split($bytes) as $byte) {
            array_push($replies, ...$resp->add($byte));
        }

        self::assertSame([['subscribe', 'ch', 1], 'OK'], $replies);
    }

    public function testResetForgetsAPartialReply(): void
    {
        $resp = new Resp();
        $resp->add("\$5\r\nab");

        $resp->reset();

        self::assertSame(['OK'], $resp->add("+OK\r\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'unknown type' => ["?x\r\n"];
        yield 'empty line' => ["\r\n"];
        yield 'integer with letters' => [":12a\r\n"];
        yield 'bulk length below -1' => ["\$-2\r\n"];
        yield 'bulk longer than announced' => ["\$1\r\nabc\r\n"];
        yield 'array length below -1' => ["*-5\r\n"];
        yield 'integer too long' => [':' . str_repeat('9', 19) . "\r\n"];
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedReplies(string $bytes): void
    {
        $this->expectException(UnexpectedValueException::class);

        (new Resp())->add($bytes);
    }
}
