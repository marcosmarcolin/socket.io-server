<?php

declare(strict_types=1);

namespace MarcosMarcolin\SocketIO\Tests\Unit\Engine;

use MarcosMarcolin\SocketIO\Engine\InvalidPacketException;
use MarcosMarcolin\SocketIO\Engine\Packet;
use MarcosMarcolin\SocketIO\Engine\PacketType;
use MarcosMarcolin\SocketIO\Engine\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testEncodesTextPacketForWebSocket(): void
    {
        self::assertSame('4hello', Parser::encodePacket(new Packet(PacketType::Message, 'hello')));
        self::assertSame('2', Parser::encodePacket(new Packet(PacketType::Ping)));
    }

    public function testEncodesBinaryPacketForWebSocketAsIs(): void
    {
        $packet = new Packet(PacketType::Message, "\x01\x02\x03\x04", true);

        self::assertSame("\x01\x02\x03\x04", Parser::encodePacket($packet));
    }

    public function testDecodesWebSocketFrames(): void
    {
        $text = Parser::decodePacket('4hello');
        self::assertSame(PacketType::Message, $text->type);
        self::assertSame('hello', $text->data);
        self::assertFalse($text->binary);

        $binary = Parser::decodePacket("\x01\x02", true);
        self::assertSame(PacketType::Message, $binary->type);
        self::assertSame("\x01\x02", $binary->data);
        self::assertTrue($binary->binary);

        self::assertSame('probe', Parser::decodePacket('2probe')->data);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFrames(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'unknown type' => ['7'];
    }

    #[DataProvider('invalidFrames')]
    public function testRejectsInvalidFrames(string $frame): void
    {
        $this->expectException(InvalidPacketException::class);

        Parser::decodePacket($frame);
    }

    public function testEncodesPayloadWithSeparatorAndBase64Binary(): void
    {
        $payload = Parser::encodePayload([
            new Packet(PacketType::Message, 'hello'),
            new Packet(PacketType::Ping),
            new Packet(PacketType::Message, "\x01\x02\x03\x04", true),
        ]);

        self::assertSame("4hello\x1e2\x1ebAQIDBA==", $payload);
    }

    public function testDecodesPayloadFromTheSpec(): void
    {
        $packets = Parser::decodePayload("4hello\x1ebAQIDBA==");

        self::assertCount(2, $packets);
        self::assertSame('hello', $packets[0]->data);
        self::assertFalse($packets[0]->binary);
        self::assertSame("\x01\x02\x03\x04", $packets[1]->data);
        self::assertTrue($packets[1]->binary);
    }

    public function testPayloadRoundTripsUnicode(): void
    {
        $packets = Parser::decodePayload(Parser::encodePayload([new Packet(PacketType::Message, '€ olá')]));

        self::assertSame('€ olá', $packets[0]->data);
    }

    public function testRejectsInvalidPayloads(): void
    {
        foreach (['abc', "4ok\x1e", 'b!!!'] as $payload) {
            try {
                Parser::decodePayload($payload);
                self::fail("Accepted invalid payload: $payload");
            } catch (InvalidPacketException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
