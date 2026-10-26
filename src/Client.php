<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use Marcolin\SocketIO\Engine\CloseReason;
use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Engine\SocketState;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use Throwable;

/**
 * One Engine.IO connection and its sockets, one per namespace.
 *
 * @internal created by SocketIO for each Engine.IO session
 */
final class Client
{
    /** @var array<string, Socket> by namespace name */
    private array $sockets = [];

    private readonly Decoder $decoder;

    private ?int $connectTimer;

    private bool $closed = false;

    /** @var array<string, Closure> listeners on the Engine.IO session */
    private array $listeners;

    public function __construct(private readonly SocketIO $server, public readonly EngineSocket $conn)
    {
        $this->decoder = new Decoder();
        $this->listeners = [
            'message' => function (string|Binary $data): void {
                $this->onData($data);
            },
            'close' => function (CloseReason $reason, ?Throwable $error): void {
                $this->onClose(DisconnectReason::from($reason->value), $error);
            },
        ];
        foreach ($this->listeners as $event => $listener) {
            $conn->on($event, $listener);
        }

        // Without a CONNECT to some namespace in time, the connection is closed.
        $this->connectTimer = $server->scheduler()->after($server->connectTimeout, function (): void {
            $this->connectTimer = null;
            if ($this->sockets === []) {
                $this->log(sprintf('joined no namespace within %d ms', $this->server->connectTimeout));
                $this->close();
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->conn->state() === SocketState::Open;
    }

    /**
     * Encodes and sends a packet.
     *
     * @param bool $volatile drop the packet if the transport can't take it right now
     */
    public function packet(Packet $packet, bool $volatile = false): void
    {
        if (! $this->isOpen()) {
            return;
        }
        $this->write($this->server->encoder()->encode($packet), $volatile);
    }

    /**
     * Sends already encoded parts: the text, then the attachments.
     *
     * @param list<string|Binary> $parts
     */
    public function write(array $parts, bool $volatile = false): void
    {
        if (! $this->isOpen() || ($volatile && ! $this->conn->transport()->isWritable())) {
            return;
        }
        foreach ($parts as $part) {
            $this->conn->send($part);
        }
    }

    /**
     * Disconnects every socket, then closes the connection.
     */
    public function disconnect(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        $this->sockets = [];
        $this->close();
    }

    public function remove(Socket $socket): void
    {
        if (($this->sockets[$socket->nsp->name] ?? null) === $socket) {
            unset($this->sockets[$socket->nsp->name]);
        }
    }

    private function close(): void
    {
        if ($this->isOpen()) {
            $this->conn->close();
            $this->onClose(DisconnectReason::ForcedServerClose);
        }
    }

    private function onData(string|Binary $data): void
    {
        try {
            $packet = $this->decoder->add($data);
        } catch (InvalidPacketException $e) {
            $this->log('sent an invalid packet: ' . $e->getMessage());
            $this->onError($e);

            return;
        }
        if ($packet !== null) {
            $this->onPacket($packet);
        }
    }

    private function onPacket(Packet $packet): void
    {
        $socket = $this->sockets[$packet->nsp] ?? null;
        if ($socket === null && $packet->type === PacketType::Connect) {
            $this->connect($packet->nsp, is_array($packet->data) ? $packet->data : []);
        } elseif (
            $socket !== null
            && $packet->type !== PacketType::Connect
            && $packet->type !== PacketType::ConnectError
        ) {
            $socket->onPacket($packet);
        } else {
            // A second CONNECT, or a packet for a namespace this connection didn't join.
            $this->log(sprintf('sent a packet of type %d out of turn for %s', $packet->type->value, $packet->nsp));
            $this->close();
        }
    }

    /**
     * @param array<mixed> $auth
     */
    private function connect(string $name, array $auth): void
    {
        if (! $this->server->hasNamespace($name)) {
            $this->log('asked for the unknown namespace ' . $name);
            $this->packet(new Packet(PacketType::ConnectError, $name, ['message' => 'Invalid namespace']));

            return;
        }

        $this->server->of($name)->add($this, $auth, function (Socket $socket): void {
            $this->sockets[$socket->nsp->name] = $socket;
            if ($this->connectTimer !== null) {
                $this->server->scheduler()->cancel($this->connectTimer);
                $this->connectTimer = null;
            }
        });
    }

    private function onError(Throwable $error): void
    {
        foreach ($this->sockets as $socket) {
            $socket->onError($error);
        }
        $this->conn->close();
    }

    private function onClose(DisconnectReason $reason, ?Throwable $error = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->listeners as $event => $listener) {
            $this->conn->off($event, $listener);
        }
        if ($this->connectTimer !== null) {
            $this->server->scheduler()->cancel($this->connectTimer);
            $this->connectTimer = null;
        }

        $sockets = $this->sockets;
        $this->sockets = [];
        foreach ($sockets as $socket) {
            $socket->onClose($reason, $error);
        }
        $this->decoder->reset();
    }

    private function log(string $message): void
    {
        $this->server->logger()->debug('socket.io:client', sprintf('session %s %s', $this->conn->id, $message));
    }
}
