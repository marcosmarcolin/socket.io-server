<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * The HTTP request that opens a WebSocket connection.
 */
final class WebSocketRequest
{
    /** @var array<string, string> header names in lower case */
    private readonly array $headers;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $path,
        private readonly array $query = [],
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers);
    }

    public function query(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
