<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Transport;

use Closure;

/**
 * One HTTP request, with its full body, and the way to answer it.
 */
interface HttpExchange
{
    /**
     * The request method, in upper case.
     */
    public function method(): string;

    /**
     * A request header, looked up case-insensitively.
     */
    public function header(string $name): ?string;

    public function body(): string;

    /**
     * Answers the request. Only the first call has an effect.
     *
     * @param array<string, string> $headers
     */
    public function respond(int $status, string $body, array $headers = []): void;

    /**
     * Registers a callback for when the client closes the connection before the answer.
     *
     * @param Closure(): void $callback
     */
    public function onAbort(Closure $callback): void;
}
