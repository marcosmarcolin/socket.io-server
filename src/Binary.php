<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

/**
 * Binary data, sent as a binary attachment instead of a JSON string.
 */
final class Binary
{
    public function __construct(public readonly string $data)
    {
    }
}
