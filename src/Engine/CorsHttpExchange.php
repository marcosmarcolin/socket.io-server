<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;

/**
 * Adds the CORS headers to every answer, including the ones a transport sends later.
 */
final class CorsHttpExchange implements HttpExchange
{
    public function __construct(
        private readonly HttpExchange $exchange,
        private readonly Cors $cors,
    ) {
    }

    public function method(): string
    {
        return $this->exchange->method();
    }

    public function path(): string
    {
        return $this->exchange->path();
    }

    public function query(string $name): ?string
    {
        return $this->exchange->query($name);
    }

    public function header(string $name): ?string
    {
        return $this->exchange->header($name);
    }

    public function body(): string
    {
        return $this->exchange->body();
    }

    public function request(): Request
    {
        return $this->exchange->request();
    }

    public function respond(int $status, string $body, array $headers = []): void
    {
        $this->exchange->respond($status, $body, $headers + $this->cors->headers($this->exchange->request()));
    }

    public function onAbort(Closure $callback): void
    {
        $this->exchange->onAbort($callback);
    }
}
