<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

interface SocketIdGenerator
{
    /**
     * A new socket id: 24 lowercase hex characters, which ChannelAdapter uses to recognize socket rooms.
     */
    public function generate(): string;
}
