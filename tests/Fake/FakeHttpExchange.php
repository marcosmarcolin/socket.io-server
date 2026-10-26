<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use Closure;
use Marcolin\SocketIO\Engine\Request;
use Marcolin\SocketIO\Engine\Transport\HttpExchange;

/**
 * An HTTP request that records its answer, and that the test can abort.
 */
final class FakeHttpExchange implements HttpExchange
{
    public ?int $status = null;

    public ?string $responseBody = null;

    /** @var array<string, string|list<string>> */
    public array $responseHeaders = [];

    /** @var list<Closure(): void> */
    private array $abortCallbacks = [];

    // One object per request, as WorkermanHttpExchange, so what engine middlewares attach stays on it.
    private ?Request $request = null;

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public function __construct(
        private readonly string $method,
        private readonly string $body = '',
        private readonly array $headers = [],
        private readonly string $path = '/engine.io/',
        private readonly array $query = [],
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fromUrl(string $method, string $url, string $body = '', array $headers = []): self
    {
        $path = parse_url($url, PHP_URL_PATH);

        return new self($method, $body, $headers, is_string($path) ? $path : '/', self::parseQuery($url));
    }

    /**
     * @return array<string, string>
     */
    public static function parseQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parsed);
        $query = [];
        foreach ($parsed as $name => $value) {
            if (is_string($value)) {
                $query[(string) $name] = $value;
            }
        }

        return $query;
    }

    public static function get(): self
    {
        return new self('GET');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function post(string $body, array $headers = []): self
    {
        return new self('POST', $body, $headers);
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
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function request(): Request
    {
        $query = http_build_query($this->query);
        $uri = $this->path . ($query === '' ? '' : '?' . $query);

        return $this->request ??= new Request($this->path, $this->query, $this->headers, $uri, '127.0.0.1');
    }

    public function respond(int $status, string $body, array $headers = []): void
    {
        if ($this->status !== null) {
            return;
        }
        $this->status = $status;
        $this->responseBody = $body;
        $this->responseHeaders = $headers;
    }

    public function onAbort(Closure $callback): void
    {
        $this->abortCallbacks[] = $callback;
    }

    /**
     * Simulates the client closing the TCP connection, possibly after the answer (keep-alive).
     */
    public function abort(): void
    {
        foreach ($this->abortCallbacks as $callback) {
            $callback();
        }
    }

    public function isAnswered(): bool
    {
        return $this->status !== null;
    }
}
