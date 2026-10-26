<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Marcolin\SocketIO\Engine\Transport\TransportException;
use Marcolin\SocketIO\Engine\Transport\TransportState;
use Marcolin\SocketIO\Event\Emitter;
use Throwable;

/**
 * An Engine.IO session: heartbeat, write buffer and transport upgrade.
 *
 * Events:
 * - `message` (string|Binary): a message from the client;
 * - `packet` (Packet): any packet from the client;
 * - `packetCreate` (Packet): a packet about to be buffered for the client: messages and pings, as in the reference;
 * - `heartbeat`: the client answered a ping;
 * - `drain`: the write buffer was handed to the transport;
 * - `upgrading` (Transport): the client probed the new transport;
 * - `upgrade` (Transport): the new transport is now the active one;
 * - `close` (CloseReason, ?Throwable): the session is closed.
 *
 * $request is the request that opened the session.
 */
final class Socket extends Emitter
{
    private SocketState $state = SocketState::Open;

    private Transport $transport;

    /** @var array<string, Closure> listeners on the active transport */
    private array $transportListeners = [];

    /** @var list<Packet> */
    private array $writeBuffer = [];

    private ?int $pingIntervalTimer = null;

    private ?int $pingTimeoutTimer = null;

    // When the running ping timeout ends, on the scheduler's clock.
    private int $pingDeadline = 0;

    // The ping timeout is extended once if other packets arrive while waiting for the pong.
    private bool $pingTimeoutRefreshed = false;

    private bool $upgrading = false;

    private bool $upgraded = false;

    private ?Transport $upgradeTransport = null;

    /** @var array<string, Closure> listeners on the transport being upgraded to */
    private array $upgradeListeners = [];

    private ?int $upgradeTimer = null;

    public function __construct(
        public readonly string $id,
        Transport $transport,
        private readonly ServerOptions $options,
        private readonly Scheduler $scheduler,
        public readonly Request $request = new Request('/'),
    ) {
        $this->setTransport($transport);
        $this->sendPacket(PacketType::Open, json_encode([
            'sid' => $id,
            // Only upgrades to transports the options allow, as in the reference.
            'upgrades' => $options->allowUpgrades
                ? array_values(array_intersect($transport->upgrades(), $options->transports))
                : [],
            'pingInterval' => $options->pingInterval,
            'pingTimeout' => $options->pingTimeout,
            'maxPayload' => $options->maxPayload,
        ], JSON_THROW_ON_ERROR));
        $this->schedulePing();
    }

    public function state(): SocketState
    {
        return $this->state;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function isUpgrading(): bool
    {
        return $this->upgrading;
    }

    public function isUpgraded(): bool
    {
        return $this->upgraded;
    }

    /**
     * Sends a message. Ignored once the session is closing.
     *
     * @param bool $compress whether the transport may compress it
     */
    public function send(string|Binary $data, bool $compress = true): void
    {
        $this->sendPacket(
            PacketType::Message,
            $data instanceof Binary ? $data->data : $data,
            $data instanceof Binary,
            $compress,
        );
    }

    /**
     * Closes the session once the write buffer is flushed; with $discard, at once, dropping what is buffered.
     */
    public function close(bool $discard = false): void
    {
        if ($discard && $this->state !== SocketState::Closed) {
            $this->state = SocketState::Closing;
            $this->transport->discard();
            $this->closeTransport();

            return;
        }
        if ($this->state !== SocketState::Open) {
            return;
        }
        $this->state = SocketState::Closing;
        if ($this->writeBuffer !== []) {
            $this->once('drain', function (): void {
                $this->closeTransport();
            });

            return;
        }
        $this->closeTransport();
    }

    /**
     * Starts upgrading to a new transport, opened by the client with this session's id.
     *
     * Only one upgrade is allowed per session; any other transport is closed.
     */
    public function maybeUpgrade(Transport $transport): void
    {
        if ($this->upgrading || $this->upgraded || $this->state !== SocketState::Open) {
            $transport->close();

            return;
        }

        $this->upgrading = true;
        $this->upgradeTransport = $transport;
        $this->upgradeTimer = $this->scheduler->after($this->options->upgradeTimeout, function (): void {
            $this->abortUpgrade();
        });
        $abort = function (): void {
            $this->abortUpgrade();
        };
        $this->upgradeListeners = [
            'packet' => function (Packet $packet): void {
                $this->onUpgradePacket($packet);
            },
            'close' => $abort,
            'error' => $abort,
        ];
        foreach ($this->upgradeListeners as $event => $listener) {
            $transport->on($event, $listener);
        }
    }

    private function setTransport(Transport $transport): void
    {
        $this->transport = $transport;
        $this->transportListeners = [
            'packet' => function (Packet $packet): void {
                $this->onPacket($packet);
            },
            'ready' => function (): void {
                $this->flush();
                $this->sendNoopForUpgrade();
            },
            'error' => function (Throwable $error): void {
                $this->onClose(
                    $error instanceof InvalidPacketException ? CloseReason::ParseError : CloseReason::TransportError,
                    $error,
                );
            },
            'close' => function (): void {
                $this->onClose(CloseReason::TransportClose);
            },
        ];
        foreach ($this->transportListeners as $event => $listener) {
            $transport->on($event, $listener);
        }
    }

    /**
     * Detaches from the active transport and closes it.
     */
    private function clearTransport(): void
    {
        foreach ($this->transportListeners as $event => $listener) {
            $this->transport->off($event, $listener);
        }
        $this->transportListeners = [];
        $this->transport->close();
        $this->cancel($this->pingTimeoutTimer);
    }

    private function onPacket(Packet $packet): void
    {
        if ($this->state !== SocketState::Open || $this->expireIfOverdue()) {
            return;
        }
        $this->dispatch('packet', $packet);

        if ($this->pingTimeoutTimer !== null && ! $this->pingTimeoutRefreshed && $packet->type !== PacketType::Pong) {
            $this->pingTimeoutRefreshed = true;
            $this->startPingTimeout();
        }

        match ($packet->type) {
            PacketType::Ping => $this->onClose(
                CloseReason::TransportError,
                new TransportException('invalid heartbeat direction'),
            ),
            PacketType::Pong => $this->onPong(),
            PacketType::Message => $this->dispatch(
                'message',
                $packet->binary ? new Binary($packet->data) : $packet->data,
            ),
            // Polling handles its own close packet; this one came over WebSocket.
            PacketType::Close => $this->onClose(CloseReason::TransportClose),
            default => null,
        };
    }

    /**
     * Closes the session if its ping timeout already ended but the timer hasn't fired yet, and says so.
     *
     * Workerman's event loop handles the sockets that are ready before the timers that are due, while the
     * reference's Node handles timers first: a request or packet arriving as the timeout ends would otherwise find
     * the session still open.
     *
     * @internal called by Server before routing a request to the session
     */
    public function expireIfOverdue(): bool
    {
        if ($this->pingTimeoutTimer === null || $this->scheduler->now() < $this->pingDeadline) {
            return false;
        }
        $this->cancel($this->pingTimeoutTimer);
        $this->onClose(CloseReason::PingTimeout);

        return true;
    }

    private function onPong(): void
    {
        $this->cancel($this->pingTimeoutTimer);
        $this->cancel($this->pingIntervalTimer);
        $this->schedulePing();
        $this->dispatch('heartbeat');
    }

    private function schedulePing(): void
    {
        $due = $this->scheduler->now() + $this->options->pingInterval;
        $this->pingIntervalTimer = $this->scheduler->after($this->options->pingInterval, function () use ($due): void {
            $this->pingIntervalTimer = null;
            $this->pingTimeoutRefreshed = false;
            $this->sendPacket(PacketType::Ping);
            // From when the ping was due: a timer that fires late (Swoole's, by a few ms) must not extend the session.
            $this->startPingTimeout($due);
        });
    }

    private function startPingTimeout(?int $from = null): void
    {
        $this->cancel($this->pingTimeoutTimer);
        $now = $this->scheduler->now();
        $this->pingDeadline = ($from ?? $now) + $this->options->pingTimeout;
        $this->pingTimeoutTimer = $this->scheduler->after(max(0, $this->pingDeadline - $now), function (): void {
            $this->pingTimeoutTimer = null;
            $this->onClose(CloseReason::PingTimeout);
        });
    }

    private function onUpgradePacket(Packet $packet): void
    {
        $transport = $this->upgradeTransport;
        if ($transport === null) {
            return;
        }

        if ($packet->type === PacketType::Ping && $packet->data === 'probe') {
            $transport->send([new Packet(PacketType::Pong, 'probe')]);
            $this->dispatch('upgrading', $transport);
            $this->sendNoopForUpgrade();

            return;
        }

        if ($packet->type !== PacketType::Upgrade || $this->state === SocketState::Closed) {
            $this->abortUpgrade();

            return;
        }

        $this->endUpgrade();
        $this->transport->discard();
        $this->upgraded = true;
        $this->clearTransport();
        $this->setTransport($transport);
        $this->dispatch('upgrade', $transport);
        $this->flush();
        if ($this->state === SocketState::Closing) {
            $transport->close(function (): void {
                $this->onClose(CloseReason::ForcedClose);
            });
        }
    }

    /**
     * Answers a pending poll with noop, so the client can stop polling and finish the upgrade.
     */
    private function sendNoopForUpgrade(): void
    {
        if ($this->upgrading && $this->transport->name() === 'polling' && $this->transport->isWritable()) {
            $this->transport->send([new Packet(PacketType::Noop)]);
        }
    }

    private function abortUpgrade(): void
    {
        $transport = $this->upgradeTransport;
        $this->endUpgrade();
        if ($transport !== null && $transport->state() === TransportState::Open) {
            $transport->close();
        }
    }

    private function endUpgrade(): void
    {
        $this->upgrading = false;
        $this->cancel($this->upgradeTimer);
        foreach ($this->upgradeListeners as $event => $listener) {
            $this->upgradeTransport?->off($event, $listener);
        }
        $this->upgradeListeners = [];
        $this->upgradeTransport = null;
    }

    private function sendPacket(
        PacketType $type,
        string $data = '',
        bool $binary = false,
        bool $compress = true,
    ): void {
        if ($this->state !== SocketState::Open) {
            return;
        }
        $packet = new Packet($type, $data, $binary, $compress);
        $this->dispatch('packetCreate', $packet);
        $this->writeBuffer[] = $packet;
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->state === SocketState::Closed || ! $this->transport->isWritable() || $this->writeBuffer === []) {
            return;
        }
        $packets = $this->writeBuffer;
        $this->writeBuffer = [];
        $this->transport->send($packets);
        $this->dispatch('drain');
    }

    private function closeTransport(): void
    {
        $this->transport->close(function (): void {
            $this->onClose(CloseReason::ForcedClose);
        });
    }

    private function onClose(CloseReason $reason, ?Throwable $error = null): void
    {
        if ($this->state === SocketState::Closed) {
            return;
        }
        $this->state = SocketState::Closed;
        $this->cancel($this->pingIntervalTimer);
        $this->cancel($this->pingTimeoutTimer);
        if ($this->upgrading) {
            $this->abortUpgrade();
        }
        $this->writeBuffer = [];
        $this->clearTransport();
        $this->dispatch('close', $reason, $error);
    }

    /**
     * Cancels a timer and forgets its id.
     *
     * @param-out null $timer
     */
    private function cancel(?int &$timer): void
    {
        if ($timer !== null) {
            $this->scheduler->cancel($timer);
            $timer = null;
        }
    }
}
