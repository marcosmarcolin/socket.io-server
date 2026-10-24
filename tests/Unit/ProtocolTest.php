<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;

/**
 * The Socket.IO scenarios of the official v5 test suite, against the server the spec describes for it.
 */
final class ProtocolTest extends TestCase
{
    private const CONNECT_TIMEOUT = 1000;

    private FakeScheduler $scheduler;

    private SocketIO $io;

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->io = new SocketIO(
            options: ['pingInterval' => 300, 'pingTimeout' => 200, 'connectTimeout' => self::CONNECT_TIMEOUT],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->io->on('connection', static function (Socket $socket): void {
            $socket->emit('auth', (object) $socket->handshake['auth']);
            $socket->on('message', static function (mixed ...$args) use ($socket): void {
                $socket->emit('message-back', ...$args);
            });
            $socket->on('message-with-ack', static function (mixed ...$args): void {
                $ack = array_pop($args);
                if ($ack instanceof Closure) {
                    $ack(...$args);
                }
            });
        });
        $this->io->of('/custom')->on('connection', static function (Socket $socket): void {
            $socket->emit('auth', (object) $socket->handshake['auth']);
        });
    }

    // connect

    public function testConnectsToTheMainNamespace(): void
    {
        $client = $this->client();

        $client->send('0');

        self::assertSame(['0{"sid":"000000000000000000000001"}', '2["auth",{}]'], $client->received());
    }

    public function testConnectsToTheMainNamespaceWithAPayload(): void
    {
        $client = $this->client();

        $client->send('0{"token":"123"}');

        self::assertSame(
            ['0{"sid":"000000000000000000000001"}', '2["auth",{"token":"123"}]'],
            $client->received(),
        );
    }

    public function testConnectsToACustomNamespace(): void
    {
        $client = $this->client();

        $client->send('0/custom,');

        self::assertSame(
            ['0/custom,{"sid":"000000000000000000000001"}', '2/custom,["auth",{}]'],
            $client->received(),
        );
    }

    public function testConnectsToACustomNamespaceWithAPayload(): void
    {
        $client = $this->client();

        $client->send('0/custom,{"token":"abc"}');

        self::assertSame(
            ['0/custom,{"sid":"000000000000000000000001"}', '2/custom,["auth",{"token":"abc"}]'],
            $client->received(),
        );
    }

    public function testRefusesAnUnknownNamespace(): void
    {
        $client = $this->client();

        $client->send('0/random');

        self::assertSame(['4/random,{"message":"Invalid namespace"}'], $client->received());
        self::assertFalse($client->isClosed());
    }

    public function testClosesOnAnInvalidHandshake(): void
    {
        $client = $this->client();

        $client->send('abc');

        self::assertTrue($client->isClosed());
    }

    public function testClosesWithoutHandshake(): void
    {
        $client = $this->client();

        // Answers the pings, so only the connect timeout can close the connection.
        for ($elapsed = 0; $elapsed < self::CONNECT_TIMEOUT - 100; $elapsed += 100) {
            $this->scheduler->advance(100);
            if (in_array('2', $client->connection->sentData(), true)) {
                $client->connection->sent = [];
                $client->connection->receive('3');
            }
        }
        $this->scheduler->advance(99);
        self::assertFalse($client->isClosed());

        $this->scheduler->advance(1);
        self::assertTrue($client->isClosed());
    }

    // disconnect

    public function testDisconnectsFromTheMainNamespaceButKeepsTheConnection(): void
    {
        $client = $this->connectedClient();

        $client->send('1');
        $this->scheduler->advance(300);

        self::assertSame(['2'], $client->connection->sentData());
        self::assertFalse($client->isClosed());
    }

    public function testConnectsThenDisconnectsFromACustomNamespace(): void
    {
        $client = $this->connectedClient();
        $client->send('0/custom');
        $client->received();

        $client->send('1/custom');
        $client->send('2["message","message to main namespace"]');

        self::assertSame(['2["message-back","message to main namespace"]'], $client->received());
    }

    // message

    public function testSendsAPlainTextPacket(): void
    {
        $client = $this->connectedClient();

        $client->send('2["message",1,"2",{"3":[true]}]');

        self::assertSame(['2["message-back",1,"2",{"3":[true]}]'], $client->received());
    }

    public function testSendsAPacketWithBinaryAttachments(): void
    {
        $client = $this->connectedClient();

        $client->send('52-["message",{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]');
        $client->sendBinary("\x01\x02\x03");
        $client->sendBinary("\x04\x05\x06");

        self::assertEquals([
            '52-["message-back",{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]',
            new Binary("\x01\x02\x03"),
            new Binary("\x04\x05\x06"),
        ], $client->received());
    }

    public function testSendsAPlainTextPacketWithAnAck(): void
    {
        $client = $this->connectedClient();

        $client->send('2456["message-with-ack",1,"2",{"3":[false]}]');

        self::assertSame(['3456[1,"2",{"3":[false]}]'], $client->received());
    }

    public function testSendsAPacketWithBinaryAttachmentsAndAnAck(): void
    {
        $client = $this->connectedClient();

        $client->send('52-789["message-with-ack",{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]');
        $client->sendBinary("\x01\x02\x03");
        $client->sendBinary("\x04\x05\x06");

        self::assertEquals([
            '62-789[{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]',
            new Binary("\x01\x02\x03"),
            new Binary("\x04\x05\x06"),
        ], $client->received());
    }

    public function testClosesOnAnUnknownPacketType(): void
    {
        $client = $this->connectedClient();

        $client->send('abc');

        self::assertTrue($client->isClosed());
    }

    public function testClosesOnAnInvalidPayloadFormat(): void
    {
        $client = $this->connectedClient();

        $client->send('2{}');

        self::assertTrue($client->isClosed());
    }

    public function testClosesOnAnInvalidAckId(): void
    {
        $client = $this->connectedClient();

        $client->send('2abc["message-with-ack",1,"2",{"3":[false]}]');

        self::assertTrue($client->isClosed());
    }

    private function client(): SocketIOClient
    {
        return new SocketIOClient($this->io);
    }

    private function connectedClient(): SocketIOClient
    {
        $client = $this->client();
        $client->send('0');
        $client->received();

        return $client;
    }
}
