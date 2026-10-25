<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Workerman\Worker;

/**
 * A worker that counts the calls to unlisten().
 */
final class SpyWorker extends Worker
{
    public int $unlistened = 0;

    public function unlisten(): void
    {
        $this->unlistened++;
        parent::unlisten();
    }
}
