<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

/**
 * A socket connected to another server, as fetchSockets() with a callback returns it: a copy of its details, and
 * methods that reach it through the adapter, as $nsp->to($id) would.
 *
 * Its handshake and data crossed as JSON, so objects in them arrive as arrays.
 */
final class RemoteSocket
{
    /**
     * @param array<mixed> $handshake
     * @param list<string> $rooms its rooms when it was fetched, its own id included
     */
    public function __construct(
        private readonly Nsp $nsp,
        public readonly string $id,
        public readonly array $handshake,
        public readonly array $rooms,
        public readonly mixed $data,
    ) {
    }

    /**
     * Sends an event to the socket. Acknowledgements aren't supported yet.
     */
    public function emit(string $event, mixed ...$args): static
    {
        $this->nsp->to($this->id)->emit($event, ...$args);

        return $this;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function join(string|array $rooms): static
    {
        $this->nsp->in($this->id)->socketsJoin($rooms);

        return $this;
    }

    public function leave(string $room): static
    {
        $this->nsp->in($this->id)->socketsLeave($room);

        return $this;
    }

    /**
     * Disconnects it from the namespace; with $close, closes its whole connection.
     */
    public function disconnect(bool $close = false): static
    {
        $this->nsp->in($this->id)->disconnectSockets($close);

        return $this;
    }
}
