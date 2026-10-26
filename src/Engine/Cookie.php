<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use InvalidArgumentException;

/**
 * The cookie that carries the session id on the polling handshake, for load balancers with sticky sessions: the
 * `cookie` option, with the reference's defaults.
 */
final class Cookie
{
    private const SAME_SITE = ['strict' => 'Strict', 'lax' => 'Lax', 'none' => 'None'];

    public readonly ?string $sameSite;

    /**
     * @param int|null $maxAge seconds
     * @param string|null $sameSite "Strict", "Lax", "None" (in any case), or null to leave it out
     * @throws InvalidArgumentException for a value that would break the header
     */
    public function __construct(
        public readonly string $name = 'io',
        public readonly ?string $path = '/',
        public readonly ?string $domain = null,
        public readonly bool $httpOnly = true,
        public readonly bool $secure = false,
        ?string $sameSite = 'Lax',
        public readonly ?int $maxAge = null,
    ) {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('The cookie "name" must be a token, like "io"');
        }
        foreach (['path' => $path, 'domain' => $domain] as $option => $value) {
            if ($value !== null && preg_match('/^[\x20-\x3A\x3C-\x7E]+$/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The cookie "%s" has an invalid character', $option));
            }
        }
        if ($sameSite !== null && ! isset(self::SAME_SITE[strtolower($sameSite)])) {
            throw new InvalidArgumentException('The cookie "sameSite" must be "Strict", "Lax", "None" or null');
        }
        $this->sameSite = $sameSite === null ? null : self::SAME_SITE[strtolower($sameSite)];
    }

    /**
     * The Set-Cookie value for a session id, with the attributes in the order of the reference's `cookie` package.
     */
    public function header(string $sid): string
    {
        $cookie = $this->name . '=' . rawurlencode($sid);
        if ($this->maxAge !== null) {
            $cookie .= '; Max-Age=' . $this->maxAge;
        }
        if ($this->domain !== null) {
            $cookie .= '; Domain=' . $this->domain;
        }
        if ($this->path !== null) {
            $cookie .= '; Path=' . $this->path;
        }
        if ($this->httpOnly) {
            $cookie .= '; HttpOnly';
        }
        if ($this->secure) {
            $cookie .= '; Secure';
        }
        if ($this->sameSite !== null) {
            $cookie .= '; SameSite=' . $this->sameSite;
        }

        return $cookie;
    }

    /**
     * The `cookie` option: false (no cookie, the default), true (the defaults), or an array of attributes.
     *
     * @throws InvalidArgumentException for unknown keys or wrong types
     */
    public static function fromOption(mixed $option): ?self
    {
        if ($option === false) {
            return null;
        }
        if ($option === true) {
            return new self();
        }
        if (! is_array($option)) {
            throw new InvalidArgumentException('The "cookie" option must be a boolean or an array of attributes');
        }
        $types = [
            'name' => 'string',
            'path' => '?string',
            'domain' => '?string',
            'httpOnly' => 'bool',
            'secure' => 'bool',
            'sameSite' => '?string',
            'maxAge' => '?int',
        ];
        foreach ($option as $key => $value) {
            $type = $types[$key] ?? throw new InvalidArgumentException(sprintf('Unknown cookie option "%s"', $key));
            $ok = match (ltrim($type, '?')) {
                'string' => is_string($value),
                'bool' => is_bool($value),
                default => is_int($value),
            } || ($value === null && str_starts_with($type, '?'));
            if (! $ok) {
                throw new InvalidArgumentException(sprintf('The cookie "%s" option must be of type %s', $key, $type));
            }
        }

        /** @var array{name?: string, path?: ?string, domain?: ?string, httpOnly?: bool, secure?: bool, sameSite?: ?string, maxAge?: ?int} $option */
        return new self(...$option);
    }
}
