<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;

/**
 * An append-only log shared by the servers, for PubSubAdapter: what must reach every server goes through it, so a
 * server that lost the broker for a while reads what it missed, and a client can recover on any server, from the
 * entry ids it got as offsets. It also keeps the sessions of the sockets that left.
 *
 * Messages are arrays of scalars and lists, never objects, as with PubSub.
 */
interface Stream
{
    /**
     * Appends a message for the servers of a namespace.
     *
     * @param array<string, mixed> $message
     * @param Closure(?string): void $appended gets the entry's id, or null when the message may not be in the stream
     */
    public function append(string $nsp, array $message, Closure $appended): void;

    /**
     * Sets the one listener of a namespace, which gets each message appended for it from now on, in order, with its
     * entry id; after an outage, it gets what was appended meanwhile.
     *
     * @param Closure(array<mixed>, string): void $listener
     */
    public function subscribe(string $nsp, Closure $listener): void;

    public function unsubscribe(string $nsp): void;

    /**
     * Keeps a session for $ttl milliseconds.
     */
    public function saveSession(string $pid, string $session, int $ttl): void;

    /**
     * Takes a session back, once, with the messages appended for the namespace after the offset, in order.
     *
     * @param Closure(?string, list<array{array<mixed>, string}>): void $callback gets the session and the messages
     *     with their entry ids; a null session when it is unknown, expired, or the offset is no longer in the stream
     */
    public function restoreSession(string $nsp, string $pid, string $offset, Closure $callback): void;
}
