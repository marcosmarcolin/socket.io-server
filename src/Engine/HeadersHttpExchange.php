<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;

/**
 * Lets the server's `initial_headers` and `headers` listeners change a session's successful polling answers.
 */
final class HeadersHttpExchange implements HttpExchange
{
    /**
     * @param Closure(ResponseHeaders, Request): void $prepare
     */
    public function __construct(
        private readonly HttpExchange $exchange,
        private readonly Closure $prepare,
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
        // As in the reference, only the data and "ok" answers; errors go out as they are.
        if ($status === 200) {
            $prepared = new ResponseHeaders($headers);
            ($this->prepare)($prepared, $this->exchange->request());
            $headers = $prepared->all();
        }
        $this->exchange->respond($status, $body, $headers);
    }

    public function onAbort(Closure $callback): void
    {
        $this->exchange->onAbort($callback);
    }
}
