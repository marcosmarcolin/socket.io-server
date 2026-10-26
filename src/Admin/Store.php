<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Admin;

/**
 * Where the Admin UI's login sessions are kept, so a reconnecting UI doesn't log in again. With several servers, a
 * shared store (a database, Redis) lets a session opened on one be used on another.
 */
interface Store
{
    public function doesSessionExist(string $sessionId): bool;

    public function saveSession(string $sessionId): void;
}
