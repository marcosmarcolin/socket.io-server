<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use InvalidArgumentException;

/**
 * The connectionStateRecovery option, as the reference's: a client that reconnects within maxDisconnectionDuration
 * gets its socket back, with its id, rooms and data, and the events it missed.
 */
final class ConnectionStateRecovery
{
    public const DEFAULT_MAX_DISCONNECTION_DURATION = 120_000;

    /**
     * @param int $maxDisconnectionDuration how long sessions and events are kept, in milliseconds
     * @param bool $skipMiddlewares whether a recovered socket connects without running the middlewares again
     */
    public function __construct(
        public readonly int $maxDisconnectionDuration = self::DEFAULT_MAX_DISCONNECTION_DURATION,
        public readonly bool $skipMiddlewares = true,
    ) {
        if ($maxDisconnectionDuration <= 0) {
            throw new InvalidArgumentException('"maxDisconnectionDuration" must be a positive integer');
        }
    }

    /**
     * The option's value: false or null (off, the default), true (the defaults), or an array of the settings.
     *
     * @throws InvalidArgumentException for any other value or setting
     */
    public static function fromOption(mixed $value): ?self
    {
        if ($value === null || $value === false) {
            return null;
        }
        if ($value === true) {
            return new self();
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('The "connectionStateRecovery" option must be a boolean or an array');
        }
        $unknown = array_diff(array_keys($value), ['maxDisconnectionDuration', 'skipMiddlewares']);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown "connectionStateRecovery" setting: %s',
                implode(', ', array_map('strval', $unknown)),
            ));
        }
        $duration = $value['maxDisconnectionDuration'] ?? self::DEFAULT_MAX_DISCONNECTION_DURATION;
        $skip = $value['skipMiddlewares'] ?? true;
        if (! is_int($duration)) {
            throw new InvalidArgumentException('"maxDisconnectionDuration" must be a positive integer');
        }
        if (! is_bool($skip)) {
            throw new InvalidArgumentException('"skipMiddlewares" must be a boolean');
        }

        return new self($duration, $skip);
    }
}
