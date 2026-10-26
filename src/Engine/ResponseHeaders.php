<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use InvalidArgumentException;

/**
 * The headers of a response about to be sent, which `initial_headers` and `headers` listeners may change, such as
 * to add a Set-Cookie. Names are matched case-insensitively.
 */
final class ResponseHeaders
{
    /** @var array<string, string|list<string>> */
    private array $headers = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->set($name, $value);
        }
    }

    /**
     * Sets a header, replacing any value it had; a list sends one line per value, as several Set-Cookie need.
     *
     * @param string|list<string> $value
     * @throws InvalidArgumentException for a name that isn't a header token, or a value with a line break
     */
    public function set(string $name, string|array $value): void
    {
        self::check($name, is_string($value) ? [$value] : $value);
        $this->remove($name);
        $this->headers[$name] = $value;
    }

    /**
     * Adds a value to a header, keeping the ones it had.
     *
     * @throws InvalidArgumentException for a name that isn't a header token, or a value with a line break
     */
    public function append(string $name, string $value): void
    {
        $current = $this->get($name) ?? [];
        $this->set($this->key($name) ?? $name, [...(is_string($current) ? [$current] : $current), $value]);
    }

    /**
     * @return string|list<string>|null
     */
    public function get(string $name): string|array|null
    {
        $key = $this->key($name);

        return $key === null ? null : $this->headers[$key];
    }

    public function remove(string $name): void
    {
        $key = $this->key($name);
        if ($key !== null) {
            unset($this->headers[$key]);
        }
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function all(): array
    {
        return $this->headers;
    }

    private function key(string $name): ?string
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param list<string> $values
     */
    private static function check(string $name, array $values): void
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid header name: %s', json_encode($name)));
        }
        foreach ($values as $value) {
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException(sprintf('The value of the %s header has a line break', $name));
            }
        }
    }
}
