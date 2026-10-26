<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Admin;

/**
 * The Admin UI's login sessions in this process's memory, as the reference's default store.
 */
final class InMemoryStore implements Store
{
    /** @var array<string, true> */
    private array $sessions = [];

    public function doesSessionExist(string $sessionId): bool
    {
        return isset($this->sessions[$sessionId]);
    }

    public function saveSession(string $sessionId): void
    {
        $this->sessions[$sessionId] = true;
    }
}
