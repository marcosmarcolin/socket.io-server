<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

interface SessionIdGenerator
{
    /**
     * A new, unguessable Engine.IO session id.
     */
    public function generate(): string;
}
