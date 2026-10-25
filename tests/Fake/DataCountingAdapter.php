<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Marcolin\SocketIO\Adapter\BroadcastOptions;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;

/**
 * Delivers a fixed event to every socket, counting how often the adapter asks for its data.
 */
final class DataCountingAdapter extends InMemoryAdapter
{
    public int $dataRequests = 0;

    public function deliverEvent(): void
    {
        $this->deliver(['2["e"]'], new BroadcastOptions(), function (): array {
            ++$this->dataRequests;

            return ['e'];
        });
    }
}
