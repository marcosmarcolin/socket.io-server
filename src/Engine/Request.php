<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * An HTTP request as Engine.IO sees it: the handshake of a session, or the request that opens a WebSocket.
 */
final class Request
{
    /** @var array<string, mixed> what engine middlewares attach, as Node middlewares decorate req; free-form */
    public array $attributes = [];

    /** @var array<string, string> header names in lower case */
    private readonly array $headers;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param string $uri the path with the query string, as received
     * @param bool $secure whether it came over TLS
     */
    public function __construct(
        public readonly string $path,
        private readonly array $query = [],
        array $headers = [],
        public readonly string $uri = '',
        public readonly string $remoteAddress = '',
        public readonly bool $secure = false,
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

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, string> lower-case names
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
