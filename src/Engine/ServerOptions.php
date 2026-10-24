<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use InvalidArgumentException;

/**
 * Engine.IO options. Times are in milliseconds, sizes in bytes.
 */
final class ServerOptions
{
    private const INT_OPTIONS = ['pingInterval', 'pingTimeout', 'maxPayload', 'upgradeTimeout'];

    /** The path the server answers on, always ending with a slash. */
    public readonly string $path;

    /**
     * @param Cors|null $cors null disables CORS
     */
    public function __construct(
        public readonly int $pingInterval = 25_000,
        public readonly int $pingTimeout = 20_000,
        public readonly int $maxPayload = 1_000_000,
        public readonly int $upgradeTimeout = 10_000,
        string $path = '/socket.io/',
        public readonly ?Cors $cors = new Cors(),
    ) {
        self::assertPositive('pingInterval', $pingInterval);
        self::assertPositive('pingTimeout', $pingTimeout);
        self::assertPositive('maxPayload', $maxPayload);
        self::assertPositive('upgradeTimeout', $upgradeTimeout);
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('The "path" option must start with "/", got "%s"', $path));
        }
        $this->path = rtrim($path, '/') . '/';
    }

    /**
     * Builds the options from the array form of the public API, rejecting unknown keys and wrong types.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $known = [...self::INT_OPTIONS, 'path', 'cors'];
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

        return new self(...$ints, path: $path, cors: self::corsFromArray($options['cors'] ?? []));
    }

    private static function corsFromArray(mixed $cors): ?Cors
    {
        if (! is_array($cors)) {
            throw new InvalidArgumentException('The "cors" option must be an array, like ["origin" => "*"]');
        }
        foreach (array_keys($cors) as $key) {
            if ($key !== 'origin') {
                throw new InvalidArgumentException(sprintf('Unknown CORS option "%s"', $key));
            }
        }
        if (! array_key_exists('origin', $cors)) {
            return new Cors();
        }

        $origin = $cors['origin'];
        if ($origin === null) {
            return null;
        }
        if ($origin === '*') {
            return new Cors('*');
        }
        if (is_array($origin) && array_is_list($origin) && $origin !== []) {
            $origins = [];
            foreach ($origin as $value) {
                $origins[] = is_string($value) && $value !== ''
                    ? $value
                    : throw new InvalidArgumentException('Each CORS origin must be a non-empty string');
            }

            return new Cors($origins);
        }

        throw new InvalidArgumentException('The CORS origin must be "*", a non-empty list of origins, or null');
    }

    private static function assertPositive(string $name, int $value): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be positive, got %d', $name, $value));
        }
    }
}
