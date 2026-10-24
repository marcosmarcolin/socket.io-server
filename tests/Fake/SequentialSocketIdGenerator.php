<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\SocketIdGenerator;

/**
 * Predictable socket ids in the real format: 000000000000000000000001, ...02...
 */
final class SequentialSocketIdGenerator implements SocketIdGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return sprintf('%024x', $this->next++);
    }
}
