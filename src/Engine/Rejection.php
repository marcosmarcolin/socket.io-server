<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * A refused request: the error code, and the message sent to the client.
 */
final class Rejection
{
    public readonly string $message;

    /**
     * @param string|null $message replaces the error's own message, as allowRequest may do
     */
    public function __construct(public readonly ServerError $error, ?string $message = null)
    {
        $this->message = $message ?? $error->message();
    }

    public function httpStatus(): int
    {
        return $this->error->httpStatus();
    }

    /**
     * The JSON body of an HTTP error response.
     */
    public function body(): string
    {
        return json_encode(
            ['code' => $this->error->value, 'message' => $this->message],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}
