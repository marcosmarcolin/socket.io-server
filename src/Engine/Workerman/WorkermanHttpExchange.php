<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * A Workerman HTTP request and its connection.
 *
 * The request is copied up front: Workerman recycles Request objects, and a poll is answered later.
 */
final class WorkermanHttpExchange implements HttpExchange
{
    private readonly string $method;

    private readonly string $path;

    /** @var array<string, string> */
    private readonly array $query;

    /** @var array<string, string> lower-case names */
    private readonly array $headers;

    private readonly string $body;

    private bool $answered = false;

    /** @var list<Closure(): void> */
    private array $abortCallbacks = [];

    public function __construct(private readonly TcpConnection $connection, Request $request)
    {
        $this->method = $request->method();
        $this->path = $request->path();
        $this->query = RequestValues::query($request);
        $this->headers = RequestValues::headers($request);
        $this->body = $request->rawBody();
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function respond(int $status, string $body, array $headers = []): void
    {
        if ($this->answered) {
            return;
        }
        $this->answered = true;
        $this->connection->send(new Response($status, $headers, $body));
    }

    public function onAbort(Closure $callback): void
    {
        $this->abortCallbacks[] = $callback;
    }

    public function isAnswered(): bool
    {
        return $this->answered;
    }

    /**
     * Called when the connection closes; only an unanswered request is aborted.
     */
    public function abort(): void
    {
        if ($this->answered) {
            return;
        }
        foreach ($this->abortCallbacks as $callback) {
            $callback();
        }
    }
}
