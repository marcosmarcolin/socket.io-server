<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\Event\Emitter;

/**
 * Exposes the protected dispatch for the tests.
 */
final class TestEmitter extends Emitter
{
    public function fire(string $event, mixed ...$args): bool
    {
        return $this->dispatch($event, ...$args);
    }
}
