<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use InvalidArgumentException;

/**
 * Where a Redis server is and how to log in: redis://[[user]:password@]host[:port][/database], or rediss:// for TLS.
 * Reserved characters in the user or password are percent-encoded.
 *
 * The database number is accepted and ignored: publish and subscribe channels are shared by every database.
 */
final class RedisUrl
{
    public const DEFAULT = 'redis://127.0.0.1:6379';

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly bool $tls,
        /** @var array<string, mixed> */
        private readonly array $tlsOptions = [],
    ) {
    }

    /**
     * @throws InvalidArgumentException for anything but a redis:// or rediss:// URL with a host
     */
    public static function parse(string $url): self
    {
        $parts = parse_url($url);
        $scheme = $parts === false ? null : ($parts['scheme'] ?? null);
        if ($parts === false || ! in_array($scheme, ['redis', 'rediss'], true) || ($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException('Invalid Redis URL, expected redis://host:port');
        }
        $path = $parts['path'] ?? '';
        if (isset($parts['query']) || isset($parts['fragment']) || preg_match('#^(/\d*)?$#', $path) !== 1) {
            throw new InvalidArgumentException('Invalid Redis URL, expected redis://host:port');
        }
        $username = isset($parts['user']) && $parts['user'] !== '' ? rawurldecode($parts['user']) : null;
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        if ($username !== null && $password === null) {
            throw new InvalidArgumentException('Invalid Redis URL, a user needs a password: redis://user:password@');
        }

        // An IPv6 host keeps its brackets, as the address to connect to needs them.
        return new self(
            $parts['host'],
            $parts['port'] ?? 6379,
            $username,
            $password,
            $scheme === 'rediss',
        );
    }

    /**
     * A copy with options for PHP's ssl stream context, such as cafile, local_cert or verify_peer.
     *
     * @param array<string, mixed> $options
     * @throws InvalidArgumentException for options on a URL without TLS
     */
    public function withTlsOptions(array $options): self
    {
        if ($options !== [] && ! $this->tls) {
            throw new InvalidArgumentException('TLS options need a rediss:// URL');
        }

        return new self($this->host, $this->port, $this->username, $this->password, $this->tls, $options);
    }

    /**
     * The ssl stream context of a rediss:// URL, or null without TLS. PHP verifies the certificate by default; the
     * peer name is the host, unless the options give another.
     *
     * @return array<string, mixed>|null
     */
    public function sslContext(): ?array
    {
        if (! $this->tls) {
            return null;
        }

        return $this->tlsOptions + ['peer_name' => trim($this->host, '[]')];
    }

    /**
     * The AUTH command to send first, or null without a password.
     *
     * @return list<string>|null
     */
    public function auth(): ?array
    {
        if ($this->password === null) {
            return null;
        }

        return $this->username === null ? ['AUTH', $this->password] : ['AUTH', $this->username, $this->password];
    }
}
