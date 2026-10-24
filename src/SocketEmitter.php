<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use InvalidArgumentException;
use LogicException;

/**
 * Emits to one socket with flags. Immutable: each modifier returns a new emitter, and the socket keeps no state.
 *
 * @property-read SocketEmitter $volatile the same emitter, dropping emits the transport can't take right now
 */
final class SocketEmitter
{
    public function __construct(
        private readonly Socket $socket,
        private readonly ?int $timeout = null,
        private readonly bool $volatile = false,
    ) {
    }

    public function __get(string $name): self
    {
        if ($name !== 'volatile') {
            throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name));
        }

        return new self($this->socket, $this->timeout, true);
    }

    /**
     * The acknowledgement callback gets a TimeoutException as first argument after this many milliseconds,
     * or null and the answer before that.
     */
    public function timeout(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('The timeout can\'t be negative');
        }

        return new self($this->socket, $milliseconds, $this->volatile);
    }

    /**
     * Kept for API compatibility: the server doesn't compress, so this has no effect.
     */
    public function compress(bool $compress): self
    {
        return $this;
    }

    public function emit(string $event, mixed ...$args): Socket
    {
        $this->socket->emitWith($event, array_values($args), $this->timeout, $this->volatile);

        return $this->socket;
    }
}
