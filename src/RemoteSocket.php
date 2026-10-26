<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use LogicException;
use Throwable;

/**
 * A socket connected to another server, as fetchSockets() with a callback returns it: a copy of its details, and
 * methods that reach it through the adapter, as $nsp->to($id) would.
 *
 * Its handshake and data crossed as JSON, so objects in them arrive as arrays.
 */
final class RemoteSocket
{
    /**
     * @internal created by the adapter
     * @param array<mixed> $handshake
     * @param list<string> $rooms its rooms when it was fetched, its own id included
     * @param int|null $timeout set by timeout()
     */
    public function __construct(
        private readonly Nsp $nsp,
        public readonly string $id,
        public readonly array $handshake,
        public readonly array $rooms,
        public readonly mixed $data,
        private readonly ?int $timeout = null,
    ) {
    }

    /**
     * Lets emit() take an acknowledgement callback, which gets a TimeoutException when the socket didn't answer in
     * time. Returns a new object.
     */
    public function timeout(int $milliseconds): self
    {
        return new self($this->nsp, $this->id, $this->handshake, $this->rooms, $this->data, $milliseconds);
    }

    /**
     * Sends an event to the socket. After timeout(), a Closure as the last argument gets (?Throwable $error,
     * mixed $answer), as with Socket::timeout().
     *
     * @throws LogicException for an acknowledgement callback without timeout()
     */
    public function emit(string $event, mixed ...$args): static
    {
        $args = array_values($args);
        $ack = $args === [] ? null : $args[array_key_last($args)];
        if (! $ack instanceof Closure) {
            $this->nsp->to($this->id)->emit($event, ...$args);

            return $this;
        }
        if ($this->timeout === null) {
            throw new LogicException(
                'An acknowledgement callback needs timeout() on a RemoteSocket, like $socket->timeout(5000)->emit(...)',
            );
        }
        array_pop($args);
        // One socket, so one answer instead of a list, as the reference's expectSingleResponse.
        $this->nsp->to($this->id)->timeout($this->timeout)->emit(
            $event,
            ...[...$args, static function (?Throwable $error, array $answers) use ($ack): void {
                $ack($error, $error === null ? ($answers[0] ?? null) : null);
            }],
        );

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
