<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use InvalidArgumentException;

/**
 * The options of RedisStreamsAdapter and Emitter::redisStreams(), with the reference's names and defaults.
 */
final class RedisStreamOptions
{
    private const DEFAULTS = [
        'streamName' => 'socket.io',
        'maxLen' => 10_000,
        'readCount' => 100,
        'blockTimeInMs' => 5_000,
        'sessionKeyPrefix' => 'sio:session:',
    ];

    /**
     * @param string $streamName the Redis stream every server appends to and reads
     * @param int $maxLen about how many entries the stream keeps (XADD MAXLEN ~), which bounds what can be recovered
     * @param int $readCount how many entries one XREAD or XRANGE call reads
     * @param int $blockTimeInMs how long one XREAD waits for entries before it is sent again
     * @param string $sessionKeyPrefix the start of the keys of the sessions kept for connection state recovery
     */
    public function __construct(
        public readonly string $streamName = self::DEFAULTS['streamName'],
        public readonly int $maxLen = self::DEFAULTS['maxLen'],
        public readonly int $readCount = self::DEFAULTS['readCount'],
        public readonly int $blockTimeInMs = self::DEFAULTS['blockTimeInMs'],
        public readonly string $sessionKeyPrefix = self::DEFAULTS['sessionKeyPrefix'],
    ) {
        foreach (['streamName' => $streamName, 'sessionKeyPrefix' => $sessionKeyPrefix] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('"%s" must be a non-empty string', $name));
            }
        }
        $counts = ['maxLen' => $maxLen, 'readCount' => $readCount, 'blockTimeInMs' => $blockTimeInMs];
        foreach ($counts as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf('"%s" must be a positive integer', $name));
            }
        }
    }

    /**
     * @param array<mixed> $options any of the constructor's names
     * @throws InvalidArgumentException for an unknown name or a wrong value
     */
    public static function fromArray(array $options): self
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown Redis stream option: %s',
                implode(', ', array_map('strval', $unknown)),
            ));
        }
        $values = $options + self::DEFAULTS;
        foreach (['streamName', 'sessionKeyPrefix'] as $name) {
            if (! is_string($values[$name])) {
                throw new InvalidArgumentException(sprintf('"%s" must be a non-empty string', $name));
            }
        }
        foreach (['maxLen', 'readCount', 'blockTimeInMs'] as $name) {
            if (! is_int($values[$name])) {
                throw new InvalidArgumentException(sprintf('"%s" must be a positive integer', $name));
            }
        }
        /** @var array{streamName: string, maxLen: int, readCount: int, blockTimeInMs: int, sessionKeyPrefix: string} $values */

        return new self(
            $values['streamName'],
            $values['maxLen'],
            $values['readCount'],
            $values['blockTimeInMs'],
            $values['sessionKeyPrefix'],
        );
    }
}
