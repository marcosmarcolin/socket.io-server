<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Marcolin\SocketIO\Engine\Scheduler;

/**
 * The WebSocket connections that wrote in this pass of the event loop, told when it ends by one zero-delay timer:
 * one timer per pass for the whole server, not one per connection, which would cost more than it saves for a
 * single message to many clients.
 */
final class WriteGroup
{
    /** @var array<int, WorkermanWebSocketConnection> by object id, in the order they wrote */
    private array $waiting = [];

    private ?int $timer = null;

    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    public function add(WorkermanWebSocketConnection $connection): void
    {
        $this->waiting[spl_object_id($connection)] = $connection;
        $this->timer ??= $this->scheduler->after(0, function (): void {
            $this->timer = null;
            $this->flush();
        });
    }

    public function remove(WorkermanWebSocketConnection $connection): void
    {
        unset($this->waiting[spl_object_id($connection)]);
    }

    /**
     * Ends the pass for every connection that wrote in it.
     */
    public function flush(): void
    {
        // Taken first: a connection may write again while another is flushed.
        $waiting = $this->waiting;
        $this->waiting = [];
        foreach ($waiting as $connection) {
            $connection->endPass();
        }
    }
}
