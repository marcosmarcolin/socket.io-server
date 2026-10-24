<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Parser;

use InvalidArgumentException;
use JsonSerializable;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Parser\Encoder;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EncoderTest extends TestCase
{
    /**
     * The examples of the spec's "Packet encoding" section.
     *
     * @return iterable<string, array{Packet, list<string|Binary>}>
     */
    public static function specExamples(): iterable
    {
        yield 'connect, main namespace' => [new Packet(PacketType::Connect), ['0']];
        yield 'connect, custom namespace' => [
            new Packet(PacketType::Connect, '/admin', ['sid' => 'oSO0OpakMV_3jnilAAAA']),
            ['0/admin,{"sid":"oSO0OpakMV_3jnilAAAA"}'],
        ];
        yield 'connect error' => [
            new Packet(PacketType::ConnectError, '/', ['message' => 'Not authorized']),
            ['4{"message":"Not authorized"}'],
        ];
        yield 'event' => [new Packet(PacketType::Event, '/', ['foo']), ['2["foo"]']];
        yield 'event, custom namespace' => [new Packet(PacketType::Event, '/admin', ['bar']), ['2/admin,["bar"]']];
        yield 'binary event' => [
            new Packet(PacketType::Event, '/', ['baz', new Binary("\x01\x02\x03\x04")]),
            ['51-["baz",{"_placeholder":true,"num":0}]', new Binary("\x01\x02\x03\x04")],
        ];
        yield 'binary event, several attachments' => [
            new Packet(PacketType::Event, '/admin', ['baz', new Binary("\x01\x02"), new Binary("\x03\x04")]),
            [
                '52-/admin,["baz",{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]',
                new Binary("\x01\x02"),
                new Binary("\x03\x04"),
            ],
        ];
        yield 'event with ack id' => [new Packet(PacketType::Event, '/', ['foo'], 12), ['212["foo"]']];
        yield 'ack, custom namespace' => [new Packet(PacketType::Ack, '/admin', ['bar'], 13), ['3/admin,13["bar"]']];
        yield 'binary ack' => [
            new Packet(PacketType::Ack, '/', ['bar', new Binary("\x01\x02\x03\x04")], 15),
            ['61-15["bar",{"_placeholder":true,"num":0}]', new Binary("\x01\x02\x03\x04")],
        ];
        yield 'disconnect, main namespace' => [new Packet(PacketType::Disconnect), ['1']];
        yield 'disconnect, custom namespace' => [new Packet(PacketType::Disconnect, '/admin'), ['1/admin,']];
    }

    /**
     * @param list<string|Binary> $expected
     */
    #[DataProvider('specExamples')]
    public function testEncodesTheSpecExamples(Packet $packet, array $expected): void
    {
        self::assertEquals($expected, (new Encoder())->encode($packet));
    }

    public function testEncodesLikeJsonStringify(): void
    {
        $data = ['a/b', 'é', "\u{2028}", new stdClass(), [], 1.5];

        $encoded = (new Encoder())->encode(new Packet(PacketType::Event, '/', $data));

        self::assertSame(["2[\"a/b\",\"é\",\"\u{2028}\",{},[],1.5]"], $encoded);
    }

    public function testFindsBinaryAtAnyDepthAndInObjects(): void
    {
        $object = new stdClass();
        $object->file = new Binary('abc');
        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['file' => new Binary('def')];
            }
        };

        $data = ['a', ['b' => ['c', $object]], $serializable];

        $encoded = (new Encoder())->encode(new Packet(PacketType::Event, '/', $data));

        self::assertEquals([
            '52-["a",{"b":["c",{"file":{"_placeholder":true,"num":0}}]},{"file":{"_placeholder":true,"num":1}}]',
            new Binary('abc'),
            new Binary('def'),
        ], $encoded);
    }

    public function testLeavesObjectsWithoutBinaryToJsonEncode(): void
    {
        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return 'custom';
            }
        };

        $packet = new Packet(PacketType::Event, '/', ['a', $serializable]);

        self::assertSame(['2["a","custom"]'], (new Encoder())->encode($packet));
    }

    public function testAcceptsNumericEventNamesAndConnectErrorStrings(): void
    {
        $encoder = new Encoder();

        $event = new Packet(PacketType::Event, '/', [1, 'a', new stdClass()]);
        $error = new Packet(PacketType::ConnectError, '/', 'Unauthorized');

        self::assertSame(['2[1,"a",{}]'], $encoder->encode($event));
        self::assertSame(['4"Unauthorized"'], $encoder->encode($error));
        self::assertSame(['3[]'], $encoder->encode(new Packet(PacketType::Ack, '/', [])));
        self::assertSame(['0{}'], $encoder->encode(new Packet(PacketType::Connect, '/', new stdClass())));
    }

    /**
     * @return iterable<string, array{Packet}>
     */
    public static function invalidPackets(): iterable
    {
        yield 'namespace without slash' => [new Packet(PacketType::Event, 'admin', ['a'])];
        yield 'namespace with comma' => [new Packet(PacketType::Event, '/a,b', ['a'])];
        yield 'negative id' => [new Packet(PacketType::Event, '/', ['a'], -1)];
        yield 'connect with a list' => [new Packet(PacketType::Connect, '/', ['a'])];
        yield 'connect with an empty array' => [new Packet(PacketType::Connect, '/', [])];
        yield 'connect with a string' => [new Packet(PacketType::Connect, '/', 'invalid')];
        yield 'disconnect with data' => [new Packet(PacketType::Disconnect, '/', new stdClass())];
        yield 'event without data' => [new Packet(PacketType::Event)];
        yield 'empty event' => [new Packet(PacketType::Event, '/', [])];
        yield 'event with an object' => [new Packet(PacketType::Event, '/', ['a' => 'b'])];
        yield 'event named with an array' => [new Packet(PacketType::Event, '/', [['a']])];
        yield 'event named null' => [new Packet(PacketType::Event, '/', [null, 'a'])];
        yield 'ack with an object' => [new Packet(PacketType::Ack, '/', new stdClass())];
        yield 'connect error with a list' => [new Packet(PacketType::ConnectError, '/', ['a'])];
        yield 'binary event given' => [new Packet(PacketType::BinaryEvent, '/', ['a', new Binary('b')])];
        yield 'binary ack given' => [new Packet(PacketType::BinaryAck, '/', [new Binary('b')])];
        yield 'binary in connect' => [new Packet(PacketType::Connect, '/', ['file' => new Binary('b')])];
        yield 'binary in connect error' => [new Packet(PacketType::ConnectError, '/', ['file' => new Binary('b')])];
        yield 'invalid UTF-8' => [new Packet(PacketType::Event, '/', ['a', "\xff"])];
        yield 'NAN' => [new Packet(PacketType::Event, '/', ['a', NAN])];
    }

    #[DataProvider('invalidPackets')]
    public function testRejectsPacketsThatCantBeSent(Packet $packet): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Encoder())->encode($packet);
    }

    public function testRejectsCircularObjects(): void
    {
        $a = new stdClass();
        $a->b = $a;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('circular');

        (new Encoder())->encode(new Packet(PacketType::Event, '/', ['a', $a], 1));
    }
}
