<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Redis;

use Closure;
use Marcolin\SocketIO\Adapter\Publisher;
use RuntimeException;
use UnexpectedValueException;

/**
 * Publishes to Redis with blocking I/O, for code outside the event loop, such as a PHP-FPM request: the Emitter's
 * connection. Messages are serialized as RedisPubSub does.
 *
 * It connects on the first publish, logs in when the URL has a password, and keeps the connection; a publish on a
 * connection that dropped reconnects once.
 */
final class BlockingRedisPublisher implements Publisher
{
    /** @var resource|null */
    private $stream = null;

    private readonly Resp $resp;

    /** @var list<mixed> replies read ahead of the commands that wait for them */
    private array $pending = [];

    /** @var Closure(): resource */
    private readonly Closure $connect;

    /**
     * @param float $timeout seconds to connect, and to wait for each reply
     * @param (Closure(): resource)|null $connect opens the stream; tests replace it
     */
    public function __construct(
        private readonly RedisUrl $url,
        private readonly float $timeout = 5.0,
        ?Closure $connect = null,
    ) {
        $this->resp = new Resp();
        $this->connect = $connect ?? $this->dial(...);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws RuntimeException when Redis can't be reached, refuses the login, or answers with an error
     */
    public function publish(string $channel, array $message): void
    {
        $command = Resp::command('PUBLISH', $channel, serialize($message));
        for ($attempt = 0;; $attempt++) {
            $fresh = $this->stream === null;
            $stream = $this->stream ?? $this->open();
            try {
                $this->reply($stream, $command);

                return;
            } catch (ConnectionLost $lost) {
                $this->close();
                // A kept connection may have dropped while idle; a new one that fails is a real failure.
                if ($fresh || $attempt > 0) {
                    throw new RuntimeException('Lost the connection to Redis: ' . $lost->getMessage(), 0, $lost);
                }
            }
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $stream = ($this->connect)();
        stream_set_timeout($stream, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1_000_000));
        $this->stream = $stream;
        $this->resp->reset();
        $this->pending = [];
        $auth = $this->url->auth();
        if ($auth !== null) {
            try {
                $this->reply($stream, Resp::command(...$auth));
            } catch (RuntimeException $error) {
                $this->close();
                throw new RuntimeException('Redis refused the login: ' . $error->getMessage(), 0, $error);
            }
        }

        return $stream;
    }

    /**
     * @return resource
     */
    private function dial()
    {
        $address = 'tcp://' . $this->url->host . ':' . $this->url->port;
        $stream = @stream_socket_client($address, $code, $message, $this->timeout);
        if ($stream === false) {
            throw new RuntimeException(sprintf(
                'Cannot connect to Redis at %s:%d: %s',
                $this->url->host,
                $this->url->port,
                $message,
            ));
        }

        return $stream;
    }

    /**
     * Sends a command and waits for its reply.
     *
     * @param resource $stream
     * @throws ConnectionLost when the connection fails
     * @throws RuntimeException for an error reply
     */
    private function reply($stream, string $command): mixed
    {
        $written = @fwrite($stream, $command);
        if ($written !== strlen($command)) {
            throw new ConnectionLost('could not send the command');
        }
        while ($this->pending === []) {
            $bytes = @fread($stream, 8192);
            if ($bytes === false || $bytes === '') {
                $timedOut = stream_get_meta_data($stream)['timed_out'];
                throw new ConnectionLost($timedOut ? 'no reply in time' : 'the connection closed');
            }
            try {
                $replies = $this->resp->add($bytes);
            } catch (UnexpectedValueException $error) {
                throw new ConnectionLost($error->getMessage(), 0, $error);
            }
            $this->pending = $replies;
        }
        $reply = array_shift($this->pending);
        if ($reply instanceof RedisError) {
            throw new RuntimeException($reply->message);
        }

        return $reply;
    }

    private function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}
