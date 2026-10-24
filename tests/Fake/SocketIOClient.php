<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\SocketIO;
use RuntimeException;

/**
 * A client speaking Socket.IO through the real Engine.IO server, over a fake WebSocket connection.
 *
 * send() takes Socket.IO packets as text, like the official suite writes them without the leading "4".
 */
final class SocketIOClient
{
    public readonly FakeWebSocketConnection $connection;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(SocketIO $io, array $headers = [], string $query = '')
    {
        parse_str($query, $extra);
        $params = ['EIO' => '4', 'transport' => 'websocket'];
        foreach ($extra as $name => $value) {
            if (is_string($value)) {
                $params[(string) $name] = $value;
            }
        }
        $uri = '/socket.io/?' . http_build_query($params);

        $this->connection = new FakeWebSocketConnection();
        $io->engine->handleWebSocket(
            new Request('/socket.io/', $params, $headers, $uri, '10.0.0.1'),
            $this->connection,
        );
        $open = $this->connection->sentData()[0] ?? '';
        if (! str_starts_with($open, '0{')) {
            throw new RuntimeException('No Engine.IO handshake: ' . $open);
        }
        $this->connection->sent = [];
    }

    public function send(string $packet): void
    {
        $this->connection->receive('4' . $packet);
    }

    public function sendBinary(string $bytes): void
    {
        $this->connection->receive($bytes, true);
    }

    /**
     * The Socket.IO messages received since the last call: text without the "4", attachments as Binary.
     * Engine.IO packets such as pings are left out.
     *
     * @return list<string|Binary>
     */
    public function received(): array
    {
        $messages = [];
        foreach ($this->connection->sent as $frame) {
            if ($frame['binary']) {
                $messages[] = new Binary($frame['data']);
            } elseif (str_starts_with($frame['data'], '4')) {
                $messages[] = substr($frame['data'], 1);
            }
        }
        $this->connection->sent = [];

        return $messages;
    }

    public function isClosed(): bool
    {
        return $this->connection->closed;
    }
}
