<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine\Transport;

use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Parser;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Transport\Polling;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Compressed polling answers, as the reference's httpCompression.
 */
final class PollingCompressionTest extends TestCase
{
    private const THRESHOLD = 100;

    public function testABigAnswerIsGzippedWhenTheClientAcceptsIt(): void
    {
        $packets = [new Packet(PacketType::Message, str_repeat('a', 200))];

        $get = $this->answer($packets, 'gzip, deflate, br');

        self::assertSame('gzip', $get->responseHeaders['Content-Encoding'] ?? null);
        self::assertSame(Parser::encodePayload($packets), gzdecode((string) $get->responseBody));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function acceptEncodings(): iterable
    {
        yield 'deflate only' => ['deflate', 'deflate'];
        yield 'gzip refused' => ['gzip;q=0, deflate', 'deflate'];
        yield 'any' => ['*', 'gzip'];
        yield 'any but gzip' => ['*, gzip;q=0', 'deflate'];
        yield 'neither' => ['br, identity', null];
        yield 'none' => ['', null];
        yield 'with spaces and case' => ['  GZIP ; q=0.5 ', 'gzip'];
    }

    #[DataProvider('acceptEncodings')]
    public function testTheEncodingFollowsAcceptEncoding(string $header, ?string $encoding): void
    {
        $packets = [new Packet(PacketType::Message, str_repeat('a', 200))];

        $get = $this->answer($packets, $header);

        self::assertSame($encoding, $get->responseHeaders['Content-Encoding'] ?? null);
        $body = (string) $get->responseBody;
        $plain = match ($encoding) {
            'gzip' => gzdecode($body),
            'deflate' => gzuncompress($body),
            default => $body,
        };
        self::assertSame(Parser::encodePayload($packets), $plain);
    }

    public function testASmallAnswerIsNotCompressed(): void
    {
        $get = $this->answer([new Packet(PacketType::Message, 'hello')], 'gzip');

        self::assertArrayNotHasKey('Content-Encoding', $get->responseHeaders);
        self::assertSame('4hello', $get->responseBody);
    }

    public function testAnAnswerIsCompressedWhenAnyPacketMayBe(): void
    {
        $big = str_repeat('b', 200);

        $none = $this->answer([new Packet(PacketType::Message, $big, compress: false)], 'gzip');
        $some = $this->answer([
            new Packet(PacketType::Message, $big, compress: false),
            new Packet(PacketType::Message, 'x'),
        ], 'gzip');

        self::assertArrayNotHasKey('Content-Encoding', $none->responseHeaders);
        self::assertSame('gzip', $some->responseHeaders['Content-Encoding'] ?? null);
    }

    public function testWithoutAThresholdNothingIsCompressed(): void
    {
        $get = $this->answer([new Packet(PacketType::Message, str_repeat('a', 5000))], 'gzip', null);

        self::assertArrayNotHasKey('Content-Encoding', $get->responseHeaders);
    }

    public function testTheOption(): void
    {
        self::assertSame(1024, ServerOptions::fromArray([])->httpCompression);
        self::assertSame(1024, ServerOptions::fromArray(['httpCompression' => true])->httpCompression);
        self::assertNull(ServerOptions::fromArray(['httpCompression' => false])->httpCompression);
        self::assertSame(0, ServerOptions::fromArray(['httpCompression' => ['threshold' => 0]])->httpCompression);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'a number' => [1024];
        yield 'an unknown key' => [['level' => 9]];
        yield 'a negative threshold' => [['threshold' => -1]];
        yield 'a threshold not an int' => [['threshold' => '1024']];
    }

    #[DataProvider('invalidOptions')]
    public function testRefusesInvalidOptions(mixed $option): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServerOptions::fromArray(['httpCompression' => $option]);
    }

    /**
     * @param list<Packet> $packets
     */
    private function answer(array $packets, string $acceptEncoding, ?int $threshold = self::THRESHOLD): FakeHttpExchange
    {
        $polling = new Polling(new FakeScheduler(), compressionThreshold: $threshold);
        $get = new FakeHttpExchange('GET', '', $acceptEncoding === '' ? [] : ['Accept-Encoding' => $acceptEncoding]);
        $polling->handleRequest($get);
        $polling->send($packets);

        return $get;
    }
}
