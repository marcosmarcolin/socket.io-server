<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\Engine\SessionIdGenerator;

/**
 * Predictable ids: sid1, sid2...
 */
final class SequentialSessionIdGenerator implements SessionIdGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return 'sid' . $this->next++;
    }
}
