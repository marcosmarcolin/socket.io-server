<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\Socket;
use Marcolin\SocketIO\Engine\Transport\WebSocket;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\FakeWebSocketConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The perMessageDeflate option: negotiating permessage-deflate (RFC 7692) and deflating big WebSocket messages.
 */
final class PerMessageDeflateTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function offers(): iterable
    {
        yield 'no header' => [null, null];
        yield 'another extension' => ['x-webkit-deflate-frame', null];
        yield 'plain' => ['permessage-deflate', 'permessage-deflate'];
        yield 'as browsers offer it' => ['permessage-deflate; client_max_window_bits', 'permessage-deflate'];
        yield 'a client window' => ['permessage-deflate; client_max_window_bits=10', 'permessage-deflate'];
        yield 'the client resets its context' => [
            'permessage-deflate; client_no_context_takeover',
            'permessage-deflate',
        ];
        yield 'our window' => ['permessage-deflate; server_max_window_bits="15"', 'permessage-deflate'];
        yield 'a smaller server window' => ['permessage-deflate; server_max_window_bits=10', null];
        yield 'the server must reset its context' => ['permessage-deflate; server_no_context_takeover', null];
        yield 'an unknown parameter' => ['permessage-deflate; x=1', null];
        yield 'a second offer that fits' => [
            'permessage-deflate; server_no_context_takeover, Permessage-Deflate; client_max_window_bits',
            'permessage-deflate',
        ];
    }

    #[DataProvider('offers')]
    public function testNegotiatesWhatTheDeflaterCanHonor(?string $offer, ?string $accepted): void
    {
        $request = $this->request($offer);

        self::assertSame($accepted, $this->server(1024)->webSocketExtension($request));
        self::assertNull($this->server(null)->webSocketExtension($request), 'off by default');
    }

    public function testTheTransportDeflatesBigCompressibleMessagesOnly(): void
    {
        $connection = new FakeWebSocketConnection();
        $transport = new WebSocket($connection, 10);

        $transport->send([
            new Packet(PacketType::Message, str_repeat('a', 20)),
            new Packet(PacketType::Message, 'short'),
            new Packet(PacketType::Message, str_repeat('b', 20), compress: false),
            new Packet(PacketType::Message, str_repeat("\x01", 20), true),
        ]);

        self::assertSame([true, false, false, true], $connection->compressed);
    }

    public function testWithoutAThresholdNothingIsDeflated(): void
    {
        $connection = new FakeWebSocketConnection();

        (new WebSocket($connection))->send([new Packet(PacketType::Message, str_repeat('a', 5000))]);

        self::assertSame([false], $connection->compressed);
    }

    public function testASessionDeflatesOnlyWhenTheExtensionWasNegotiated(): void
    {
        $server = $this->server(10);
        $sessions = [];
        $server->on('connection', static function (Socket $socket) use (&$sessions): void {
            $sessions[] = $socket;
        });
        $negotiated = new FakeWebSocketConnection();
        $plain = new FakeWebSocketConnection();

        $server->handleWebSocket($this->request('permessage-deflate'), $negotiated);
        $server->handleWebSocket($this->request(null), $plain);
        foreach ($sessions as $session) {
            $session->send(str_repeat('a', 50));
        }

        self::assertSame(true, end($negotiated->compressed));
        self::assertSame(false, end($plain->compressed));
    }

    public function testTheOption(): void
    {
        self::assertNull(ServerOptions::fromArray([])->perMessageDeflate);
        self::assertSame(1024, ServerOptions::fromArray(['perMessageDeflate' => true])->perMessageDeflate);
        self::assertSame(64, ServerOptions::fromArray(['perMessageDeflate' => ['threshold' => 64]])->perMessageDeflate);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"perMessageDeflate"');

        ServerOptions::fromArray(['perMessageDeflate' => 'yes']);
    }

    private function server(?int $threshold): Server
    {
        return new Server(new ServerOptions(perMessageDeflate: $threshold), new FakeScheduler());
    }

    private function request(?string $offer): Request
    {
        return new Request(
            '/socket.io/',
            ['EIO' => '4', 'transport' => 'websocket'],
            $offer === null ? [] : ['Sec-WebSocket-Extensions' => $offer],
        );
    }
}
