<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Throwable;

/**
 * Records the events a transport dispatches.
 */
final class TransportRecorder
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<Packet> */
    public array $packets = [];

    /** @var list<Throwable> */
    public array $errors = [];

    public function __construct(Transport $transport)
    {
        $transport->on('packet', function (Packet $packet): void {
            $this->events[] = 'packet';
            $this->packets[] = $packet;
        });
        $transport->on('error', function (Throwable $error): void {
            $this->events[] = 'error';
            $this->errors[] = $error;
        });
        foreach (['ready', 'drain', 'close'] as $event) {
            $transport->on($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }
}
