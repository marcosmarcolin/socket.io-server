<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

/**
 * Why the server rejected a request. Codes and messages match the reference implementation.
 */
enum ServerError: int
{
    case UnknownTransport = 0;
    case UnknownSid = 1;
    case BadHandshakeMethod = 2;
    case BadRequest = 3;
    case Forbidden = 4;
    case UnsupportedProtocolVersion = 5;

    public function message(): string
    {
        return match ($this) {
            self::UnknownTransport => 'Transport unknown',
            self::UnknownSid => 'Session ID unknown',
            self::BadHandshakeMethod => 'Bad handshake method',
            self::BadRequest => 'Bad request',
            self::Forbidden => 'Forbidden',
            self::UnsupportedProtocolVersion => 'Unsupported protocol version',
        };
    }

    public function httpStatus(): int
    {
        return $this === self::Forbidden ? 403 : 400;
    }
}
