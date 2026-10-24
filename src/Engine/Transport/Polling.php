<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use Closure;
use LogicException;
use Marcolin\SocketIO\Engine\InvalidPacketException;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\PacketType;
use Marcolin\SocketIO\Engine\Parser;
use Marcolin\SocketIO\Engine\Scheduler;

/**
 * HTTP long-polling: a pending GET carries packets to the client, a POST carries packets from it.
 *
 * Workerman hands over a request only once its body is complete, so POSTs are handled whole, one at a time.
 */
final class Polling extends Transport
{
    private const HEADERS = ['Cache-Control' => 'no-store'];

    private ?HttpExchange $poll = null;

    /** @var (Closure(): void)|null */
    private ?Closure $pendingClose = null;

    private ?int $closeTimer = null;

    /**
     * @param int $closeTimeout milliseconds to wait for a GET that carries the close packet
     * @param int $maxPayload largest POST body accepted, in bytes
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly int $closeTimeout = 30_000,
        private readonly int $maxPayload = 1_000_000,
    ) {
    }

    public function name(): string
    {
        return 'polling';
    }

    public function upgrades(): array
    {
        return ['websocket'];
    }

    public function handleRequest(HttpExchange $exchange): void
    {
        if ($this->state() === TransportState::Closed) {
            $exchange->respond(400, '', self::HEADERS);

            return;
        }
        match ($exchange->method()) {
            'GET' => $this->onPollRequest($exchange),
            'POST' => $this->onDataRequest($exchange),
            default => $exchange->respond(400, '', self::HEADERS),
        };
    }

    public function send(array $packets): void
    {
        $exchange = $this->poll ?? throw new LogicException('There is no pending GET request to write to');
        $this->poll = null;
        $this->writable = false;

        $close = $this->pendingClose;
        $this->pendingClose = null;
        if ($close !== null) {
            $packets[] = new Packet(PacketType::Close);
        }

        $exchange->respond(200, Parser::encodePayload($packets), [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ] + self::HEADERS);
        $this->dispatch('drain');

        if ($close !== null) {
            $close();
        }
    }

    protected function doClose(Closure $onClosed): void
    {
        $finish = function () use ($onClosed): void {
            $this->pendingClose = null;
            if ($this->closeTimer !== null) {
                $this->scheduler->cancel($this->closeTimer);
                $this->closeTimer = null;
            }
            $onClosed();
            $this->onClose();
        };

        if ($this->writable) {
            $this->send([new Packet(PacketType::Close)]);
            $finish();
        } elseif ($this->discarded) {
            $finish();
        } else {
            // The close packet goes with the next GET, if it comes in time.
            $this->pendingClose = $finish;
            $this->closeTimer = $this->scheduler->after($this->closeTimeout, $finish);
        }
    }

    protected function onClose(): void
    {
        if ($this->writable) {
            // Ends the pending GET cleanly.
            $this->send([new Packet(PacketType::Noop)]);
        }
        parent::onClose();
    }

    private function onPollRequest(HttpExchange $exchange): void
    {
        if ($this->poll !== null) {
            $this->onError(new TransportException('overlap from client'));
            $exchange->respond(400, '', self::HEADERS);

            return;
        }

        $this->poll = $exchange;
        $exchange->onAbort(function () use ($exchange): void {
            if ($this->poll !== $exchange) {
                return;
            }
            $this->poll = null;
            $this->writable = false;
            $this->onError(new TransportException('poll connection closed prematurely'));
        });

        $this->writable = true;
        $this->dispatch('ready');

        // A ready listener may have written already.
        if ($this->isWritable() && $this->pendingClose !== null) {
            $this->send([new Packet(PacketType::Noop)]);
        }
    }

    private function onDataRequest(HttpExchange $exchange): void
    {
        // Protocol v4 sends binary as base64 text, never as a binary body.
        if (str_starts_with(strtolower($exchange->header('Content-Type') ?? ''), 'application/octet-stream')) {
            $exchange->respond(400, '', self::HEADERS);
            $this->onError(new TransportException('invalid content'));

            return;
        }

        // As in the reference, a too large body is refused but the session goes on.
        if (strlen($exchange->body()) > $this->maxPayload) {
            $exchange->respond(413, '', self::HEADERS);

            return;
        }

        try {
            $packets = Parser::decodePayload($exchange->body());
        } catch (InvalidPacketException $e) {
            $exchange->respond(400, '', self::HEADERS);
            $this->onError($e);

            return;
        }

        foreach ($packets as $packet) {
            if ($packet->type === PacketType::Close) {
                $this->onClose();
                break;
            }
            $this->onPacket($packet);
        }

        // text/html avoids a download dialog in some user agents.
        $exchange->respond(200, 'ok', ['Content-Type' => 'text/html'] + self::HEADERS);
    }
}
