<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use Closure;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Event\Emitter;
use Throwable;

/**
 * Base of the Engine.IO transports.
 *
 * Events:
 * - `packet` (Packet): a packet was received;
 * - `ready`: the transport became writable;
 * - `drain`: the packets given to send() were written;
 * - `error` (Throwable): an InvalidPacketException for a malformed packet, a TransportException otherwise;
 * - `close`: the transport is closed.
 */
abstract class Transport extends Emitter
{
    protected bool $writable = false;

    protected bool $discarded = false;

    private TransportState $state = TransportState::Open;

    abstract public function name(): string;

    /**
     * Writes the packets. Only call it while the transport is writable.
     *
     * @param list<Packet> $packets
     */
    abstract public function send(array $packets): void;

    /**
     * The transports this one can be upgraded to.
     *
     * @return list<string>
     */
    public function upgrades(): array
    {
        return [];
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function state(): TransportState
    {
        return $this->state;
    }

    /**
     * Flags the transport as replaced by an upgrade, so it can close without an orderly goodbye.
     */
    public function discard(): void
    {
        $this->discarded = true;
    }

    /**
     * @param (Closure(): void)|null $onClosed called once the transport has done its part of the close
     */
    public function close(?Closure $onClosed = null): void
    {
        if ($this->state !== TransportState::Open) {
            return;
        }
        $this->state = TransportState::Closing;
        $this->doClose($onClosed ?? static function (): void {
        });
    }

    /**
     * @param Closure(): void $onClosed
     */
    abstract protected function doClose(Closure $onClosed): void;

    protected function onPacket(Packet $packet): void
    {
        $this->dispatch('packet', $packet);
    }

    protected function onError(Throwable $error): void
    {
        $this->dispatch('error', $error);
    }

    protected function onClose(): void
    {
        if ($this->state === TransportState::Closed) {
            return;
        }
        $this->state = TransportState::Closed;
        $this->writable = false;
        $this->dispatch('close');
    }
}
