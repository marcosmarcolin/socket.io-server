<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Parser;

use InvalidArgumentException;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Packet}>
     */
    public static function textPackets(): iterable
    {
        yield 'connect' => ['0', new Packet(PacketType::Connect)];
        yield 'connect with auth' => [
            '0/admin,{"token":"123"}',
            new Packet(PacketType::Connect, '/admin', ['token' => '123']),
        ];
        yield 'connect, namespace without comma' => ['0/admin', new Packet(PacketType::Connect, '/admin')];
        yield 'connect with an empty object' => ['0{}', new Packet(PacketType::Connect, '/', [])];
        yield 'disconnect' => ['1', new Packet(PacketType::Disconnect)];
        yield 'disconnect, custom namespace' => ['1/admin,', new Packet(PacketType::Disconnect, '/admin')];
        yield 'event' => ['2["foo"]', new Packet(PacketType::Event, '/', ['foo'])];
        yield 'event with numeric name' => ['2[1,"a",{}]', new Packet(PacketType::Event, '/', [1, 'a', []])];
        yield 'event named with an empty string' => ['2[""]', new Packet(PacketType::Event, '/', [''])];
        yield 'event with ack id' => ['212["foo"]', new Packet(PacketType::Event, '/', ['foo'], 12)];
        yield 'event, namespace and ack id' => [
            '2/admin,1["tellme"]',
            new Packet(PacketType::Event, '/admin', ['tellme'], 1),
        ];
        yield 'ack id with leading zeros' => ['3007[]', new Packet(PacketType::Ack, '/', [], 7)];
        yield 'ack id with many leading zeros' => [
            '3' . str_repeat('0', 30) . '5[]',
            new Packet(PacketType::Ack, '/', [], 5),
        ];
        yield 'ack' => ['3/admin,13["bar"]', new Packet(PacketType::Ack, '/admin', ['bar'], 13)];
        yield 'empty ack' => ['312[]', new Packet(PacketType::Ack, '/', [], 12)];
        yield 'connect error object' => [
            '4{"message":"Not authorized"}',
            new Packet(PacketType::ConnectError, '/', ['message' => 'Not authorized']),
        ];
        yield 'connect error string' => ['4"Unauthorized"', new Packet(PacketType::ConnectError, '/', 'Unauthorized')];
        yield 'placeholder-like data outside a binary packet' => [
            '2["a",{"_placeholder":true,"num":0}]',
            new Packet(PacketType::Event, '/', ['a', ['_placeholder' => true, 'num' => 0]]),
        ];
    }

    #[DataProvider('textPackets')]
    public function testDecodesTextPackets(string $message, Packet $expected): void
    {
        self::assertEquals($expected, (new Decoder())->add($message));
    }

    public function testReconstructsBinaryPackets(): void
    {
        $decoder = new Decoder();

        self::assertNull($decoder->add(
            '52-/admin,7["baz",{"_placeholder":true,"num":1},{"x":{"_placeholder":true,"num":0}}]',
        ));
        self::assertNull($decoder->add(new Binary("\x01\x02")));
        $packet = $decoder->add(new Binary("\x03\x04"));

        self::assertEquals(
            new Packet(
                PacketType::Event,
                '/admin',
                ['baz', new Binary("\x03\x04"), ['x' => new Binary("\x01\x02")]],
                7,
            ),
            $packet,
        );
    }

    public function testReconstructsBinaryAcks(): void
    {
        $decoder = new Decoder();

        $decoder->add('61-15["bar",{"_placeholder":true,"num":0}]');

        self::assertEquals(
            new Packet(PacketType::Ack, '/', ['bar', new Binary("\x01\x02\x03\x04")], 15),
            $decoder->add(new Binary("\x01\x02\x03\x04")),
        );
    }

    public function testDecodesAgainAfterABinaryPacket(): void
    {
        $decoder = new Decoder();
        $decoder->add('51-["a",{"_placeholder":true,"num":0}]');
        $decoder->add(new Binary('x'));

        self::assertEquals(new Packet(PacketType::Event, '/', ['b']), $decoder->add('2["b"]'));
    }

    /**
     * The invalid payloads of the reference's parser tests, plus the spec's mandatory EVENT and ACK payloads.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'broken JSON' => ['442["some","data"'];
        yield 'connect with a string' => ['0/admin,"invalid"'];
        yield 'connect with an array' => ['0[]'];
        yield 'disconnect with data' => ['1/admin,{}'];
        yield 'event with a broken string' => ['2/admin,"invalid'];
        yield 'event with an object' => ['2/admin,{}'];
        yield 'event named with an object' => ['2[{"toString":"foo"}]'];
        yield 'event named true' => ['2[true,"foo"]'];
        yield 'event named null' => ['2[null,"bar"]'];
        yield 'reserved name connect' => ['2["connect"]'];
        yield 'reserved name disconnect' => ['2["disconnect","123"]'];
        yield 'reserved name newListener' => ['2["newListener"]'];
        yield 'empty event' => ['2[]'];
        yield 'event without payload' => ['2'];
        yield 'ack without payload' => ['312'];
        yield 'ack with an object' => ['3{}'];
        yield 'connect error without payload' => ['4'];
        yield 'connect error with a list' => ['4[]'];
        yield 'binary event named with a placeholder' => ['51-[{"_placeholder":true,"num":0}]'];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayloads(string $message): void
    {
        $this->expectExceptionObject(new InvalidPacketException('invalid payload'));

        (new Decoder())->add($message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'unknown type' => ['999', 'unknown packet type 9'];
        yield 'type 7' => ['7', 'unknown packet type 7'];
        yield 'letter' => ['a', 'unknown packet type a'];
        yield 'empty' => ['', 'unknown packet type '];
        yield 'binary without count' => ['5', 'Illegal attachments'];
        yield 'binary without dash' => ['51', 'Illegal attachments'];
        yield 'zero attachments' => ['50-', 'Illegal attachments'];
        yield 'letter count' => ['5a-', 'Illegal attachments'];
        yield 'decimal count' => ['51.23-', 'Illegal attachments'];
        yield 'negative count' => ['5-1-', 'Illegal attachments'];
        yield 'ack id overflow' => ['3' . str_repeat('9', 20) . '[]', 'invalid acknowledgement id'];
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsInvalidHeaders(string $message, string $error): void
    {
        $this->expectExceptionObject(new InvalidPacketException($error));

        (new Decoder())->add($message);
    }

    public function testLimitsTheAttachmentCount(): void
    {
        $decoder = new Decoder(maxAttachments: 2);

        $this->expectExceptionObject(new InvalidPacketException('too many attachments'));

        $decoder->add('53-["hello",{"_placeholder":true,"num":0},{"_placeholder":true,"num":1},'
            . '{"_placeholder":true,"num":2}]');
    }

    public function testAllowsTenAttachmentsByDefault(): void
    {
        self::assertNull((new Decoder())->add('510-["a"]'));

        $this->expectExceptionObject(new InvalidPacketException('too many attachments'));
        (new Decoder())->add('511-["a"]');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPlaceholders(): iterable
    {
        yield 'string num' => ['51-["hello",{"_placeholder":true,"num":"splice"}]'];
        yield 'out of bounds' => ['51-["hello",{"_placeholder":true,"num":1}]'];
        yield 'negative num' => ['51-["hello",{"_placeholder":true,"num":-1}]'];
        yield 'missing num' => ['51-["hello",{"_placeholder":true}]'];
    }

    #[DataProvider('invalidPlaceholders')]
    public function testRejectsInvalidPlaceholders(string $message): void
    {
        $decoder = new Decoder();
        $decoder->add($message);

        $this->expectExceptionObject(new InvalidPacketException('illegal attachments'));

        $decoder->add(new Binary('world'));
    }

    public function testRejectsTextWhileWaitingForAttachments(): void
    {
        $decoder = new Decoder();
        $decoder->add('51-["a",{"_placeholder":true,"num":0}]');

        $this->expectExceptionObject(new InvalidPacketException('got plaintext data when reconstructing a packet'));

        $decoder->add('2["b"]');
    }

    public function testRejectsAnUnexpectedAttachment(): void
    {
        $this->expectExceptionObject(new InvalidPacketException('got binary data when not reconstructing a packet'));

        (new Decoder())->add(new Binary('x'));
    }

    public function testResetDropsAPendingBinaryPacket(): void
    {
        $decoder = new Decoder();
        $decoder->add('51-["hello"]');

        $decoder->reset();

        self::assertEquals(new Packet(PacketType::Event, '/', ['hello']), $decoder->add('2["hello"]'));
    }

    public function testRejectsAZeroAttachmentLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Decoder(maxAttachments: 0);
    }
}
