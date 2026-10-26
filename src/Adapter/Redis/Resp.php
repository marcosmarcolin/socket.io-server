<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use UnexpectedValueException;

/**
 * The RESP2 protocol of Redis: commands out, replies in. A decoder per connection, since replies arrive in chunks.
 *
 * Replies decode to string (simple and bulk strings), int, null, RedisError, or a list of replies.
 */
final class Resp
{
    private string $buffer = '';

    /**
     * A command as Redis expects it from clients: an array of bulk strings.
     */
    public static function command(string ...$arguments): string
    {
        $command = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $command .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        return $command;
    }

    /**
     * Adds received bytes and returns the replies they complete, in order.
     *
     * @return list<mixed>
     * @throws UnexpectedValueException on bytes that aren't RESP2; the connection can't go on after it
     */
    public function add(string $bytes): array
    {
        $this->buffer .= $bytes;
        $replies = [];
        $offset = 0;
        while (($reply = $this->reply($offset)) !== null) {
            [$replies[], $offset] = $reply;
        }
        $this->buffer = substr($this->buffer, $offset);

        return $replies;
    }

    /**
     * Forgets a partial reply, for a new connection.
     */
    public function reset(): void
    {
        $this->buffer = '';
    }

    /**
     * The reply at the offset and the offset after it, or null while it is incomplete.
     *
     * @return array{mixed, int}|null
     */
    private function reply(int $offset): ?array
    {
        $end = strpos($this->buffer, "\r\n", $offset);
        if ($end === false) {
            return null;
        }
        $type = $this->buffer[$offset] ?? '';
        $line = substr($this->buffer, $offset + 1, $end - $offset - 1);
        $next = $end + 2;

        return match ($type) {
            '+' => [$line, $next],
            '-' => [new RedisError($line), $next],
            ':' => [self::integer($line), $next],
            '$' => $this->bulk(self::integer($line), $next),
            '*' => $this->array(self::integer($line), $next),
            default => throw new UnexpectedValueException('Unknown Redis reply type: ' . var_export($type, true)),
        };
    }

    /**
     * @return array{?string, int}|null
     */
    private function bulk(int $length, int $offset): ?array
    {
        if ($length === -1) {
            return [null, $offset];
        }
        if ($length < 0) {
            throw new UnexpectedValueException('Invalid bulk string length from Redis: ' . $length);
        }
        if (strlen($this->buffer) < $offset + $length + 2) {
            return null;
        }
        if (substr($this->buffer, $offset + $length, 2) !== "\r\n") {
            throw new UnexpectedValueException('A bulk string from Redis is longer than announced');
        }

        return [substr($this->buffer, $offset, $length), $offset + $length + 2];
    }

    /**
     * @return array{?list<mixed>, int}|null
     */
    private function array(int $count, int $offset): ?array
    {
        if ($count === -1) {
            return [null, $offset];
        }
        if ($count < 0) {
            throw new UnexpectedValueException('Invalid array length from Redis: ' . $count);
        }
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $item = $this->reply($offset);
            if ($item === null) {
                return null;
            }
            [$items[], $offset] = $item;
        }

        return [$items, $offset];
    }

    private static function integer(string $line): int
    {
        if (preg_match('/^-?\d{1,18}$/', $line) !== 1) {
            throw new UnexpectedValueException('Invalid integer from Redis: ' . var_export($line, true));
        }

        return (int) $line;
    }
}
