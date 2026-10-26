<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Adapter\Redis\Dialer;
use Marcolin\SocketIO\Adapter\Redis\Link;

/**
 * Records each dial as a FakeLink, which the test opens, feeds and drops.
 */
final class FakeDialer implements Dialer
{
    /** @var list<FakeLink> */
    public array $links = [];

    /** @var list<string> */
    public array $addresses = [];

    public function dial(
        string $host,
        int $port,
        Closure $onOpen,
        Closure $onData,
        Closure $onError,
        Closure $onClose,
    ): Link {
        $this->addresses[] = $host . ':' . $port;
        $link = new FakeLink($onOpen, $onData, $onError, $onClose);
        $this->links[] = $link;

        return $link;
    }

    /**
     * @phpstan-impure
     */
    public function last(): FakeLink
    {
        $link = end($this->links);
        if ($link === false) {
            throw new \LogicException('Nothing was dialed');
        }

        return $link;
    }
}
