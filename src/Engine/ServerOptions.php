<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use InvalidArgumentException;

/**
 * Engine.IO options. Times are in milliseconds, sizes in bytes.
 */
final class ServerOptions
{
    private const INT_OPTIONS = ['pingInterval', 'pingTimeout', 'maxPayload', 'upgradeTimeout'];

    public const TRANSPORTS = ['polling', 'websocket'];

    /** The path the server answers on, always ending with a slash. */
    public readonly string $path;

    /**
     * @param Cors|null $cors null disables CORS
     * @param list<string> $transports the transports clients may use: "polling", "websocket", or both
     */
    public function __construct(
        public readonly int $pingInterval = 25_000,
        public readonly int $pingTimeout = 20_000,
        public readonly int $maxPayload = 1_000_000,
        public readonly int $upgradeTimeout = 10_000,
        string $path = '/socket.io/',
        public readonly ?Cors $cors = new Cors(),
        public readonly array $transports = self::TRANSPORTS,
    ) {
        self::assertPositive('pingInterval', $pingInterval);
        self::assertPositive('pingTimeout', $pingTimeout);
        self::assertPositive('maxPayload', $maxPayload);
        self::assertPositive('upgradeTimeout', $upgradeTimeout);
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('The "path" option must start with "/", got "%s"', $path));
        }
        $this->path = rtrim($path, '/') . '/';
        $valid = $transports !== []
            && array_diff($transports, self::TRANSPORTS) === []
            && count(array_unique($transports)) === count($transports);
        if (! $valid) {
            throw new InvalidArgumentException('The "transports" option must list "polling", "websocket", or both');
        }
    }

    /**
     * Builds the options from the array form of the public API, rejecting unknown keys and wrong types.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $known = [...self::INT_OPTIONS, 'path', 'cors', 'transports'];
        foreach (array_keys($options) as $key) {
            if (! in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown Engine.IO option "%s"', $key));
            }
        }

        $ints = [];
        foreach (self::INT_OPTIONS as $name) {
            if (array_key_exists($name, $options)) {
                $ints[$name] = is_int($options[$name])
                    ? $options[$name]
                    : throw new InvalidArgumentException(sprintf('The "%s" option must be an integer', $name));
            }
        }

        $path = $options['path'] ?? '/socket.io/';
        if (! is_string($path)) {
            throw new InvalidArgumentException('The "path" option must be a string');
        }

        $transports = $options['transports'] ?? self::TRANSPORTS;
        if (! is_array($transports) || ! array_is_list($transports)) {
            throw new InvalidArgumentException('The "transports" option must be a list, like ["websocket"]');
        }
        $names = [];
        foreach ($transports as $transport) {
            $names[] = is_string($transport)
                ? $transport
                : throw new InvalidArgumentException('Each transport must be "polling" or "websocket"');
        }

        return new self(
            ...$ints,
            path: $path,
            cors: self::corsFromArray($options['cors'] ?? []),
            transports: $names,
        );
    }

    private static function corsFromArray(mixed $cors): ?Cors
    {
        if (! is_array($cors)) {
            throw new InvalidArgumentException('The "cors" option must be an array, like ["origin" => "*"]');
        }
        foreach (array_keys($cors) as $key) {
            if ($key !== 'origin' && $key !== 'credentials') {
                throw new InvalidArgumentException(sprintf('Unknown CORS option "%s"', $key));
            }
        }
        $credentials = $cors['credentials'] ?? false;
        if (! is_bool($credentials)) {
            throw new InvalidArgumentException('The CORS "credentials" option must be a boolean');
        }

        $origin = array_key_exists('origin', $cors) ? $cors['origin'] : '*';
        if ($origin === null) {
            return null;
        }
        if ($origin === '*' || $origin instanceof Closure) {
            return new Cors($origin, $credentials);
        }
        if (is_array($origin) && array_is_list($origin) && $origin !== []) {
            $origins = [];
            foreach ($origin as $value) {
                $origins[] = is_string($value) && $value !== ''
                    ? $value
                    : throw new InvalidArgumentException('Each CORS origin must be a non-empty string');
            }

            return new Cors($origins, $credentials);
        }

        throw new InvalidArgumentException(
            'The CORS origin must be "*", a non-empty list of origins, a Closure, or null',
        );
    }

    private static function assertPositive(string $name, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be positive, got %d', $name, $value));
        }
    }
}
