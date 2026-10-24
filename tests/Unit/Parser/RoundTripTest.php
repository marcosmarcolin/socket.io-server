<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Parser;

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\Encoder;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Encoding then decoding gives the packet back, as in the reference's helpers.test() and test_bin().
 * Empty objects are the exception: they come back as empty arrays.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{Packet}>
     */
    public static function packets(): iterable
    {
        yield 'connection' => [new Packet(PacketType::Connect, '/woot', ['token' => '123'])];
        yield 'disconnection' => [new Packet(PacketType::Disconnect, '/woot')];
        yield 'event' => [new Packet(PacketType::Event, '/', ['a', 1, ['b' => 2]])];
        yield 'event with integer name' => [new Packet(PacketType::Event, '/', [1, 'a', ['b' => 2]])];
        yield 'event with ack' => [new Packet(PacketType::Event, '/test', ['a', 1, ['b' => 2]], 1)];
        yield 'ack' => [new Packet(PacketType::Ack, '/', ['a', 1, ['b' => 2]], 123)];
        yield 'connect error' => [new Packet(PacketType::ConnectError, '/', 'Unauthorized')];
        yield 'connect error object' => [new Packet(PacketType::ConnectError, '/', ['message' => 'Unauthorized'])];
        yield 'binary' => [new Packet(PacketType::Event, '/cool', ['a', new Binary('abc')], 23)];
        yield 'nested binary' => [new Packet(PacketType::Event, '/cool', ['a', ['b' => ['c', new Binary('abc')]]], 23)];
        yield 'binary ack' => [new Packet(PacketType::Ack, '/back', ['a', new Binary('xxx'), ['b' => 2]], 127)];
        $allBytes = new Binary(implode('', array_map('chr', range(0, 255))));
        yield 'all byte values' => [new Packet(PacketType::Event, '/', ['bytes', $allBytes])];
    }

    #[DataProvider('packets')]
    public function testDecodesWhatItEncodes(Packet $packet): void
    {
        $decoder = new Decoder();
        $decoded = null;
        foreach ((new Encoder())->encode($packet) as $message) {
            $decoded = $decoder->add($message);
        }

        self::assertEquals($packet, $decoded);
    }
}
