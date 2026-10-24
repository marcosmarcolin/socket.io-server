<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Closure;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;
use Workerman\Connection\TcpConnection;
use Marcolin\SocketIO\Engine\Request;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response;

/**
 * A Workerman HTTP request and its connection.
 *
 * The request is copied up front: Workerman recycles Request objects, and a poll is answered later.
 */
final class WorkermanHttpExchange implements HttpExchange
{
    private readonly string $method;

    private readonly Request $request;

    private readonly string $body;

    private bool $answered = false;

    /** @var list<Closure(): void> */
    private array $abortCallbacks = [];

    public function __construct(private readonly TcpConnection $connection, WorkermanRequest $request)
    {
        $this->method = $request->method();
        $this->request = RequestValues::request($connection, $request);
        $this->body = $request->rawBody();
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->request->path;
    }

    public function query(string $name): ?string
    {
        return $this->request->query($name);
    }

    public function header(string $name): ?string
    {
        return $this->request->header($name);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function request(): Request
    {
        return $this->request;
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
