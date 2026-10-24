<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use Marcolin\SocketIO\Engine\ServerError;
use PHPUnit\Framework\TestCase;

final class ServerErrorTest extends TestCase
{
    public function testMatchesTheReferenceCodesAndMessages(): void
    {
        self::assertSame([
            0 => 'Transport unknown',
            1 => 'Session ID unknown',
            2 => 'Bad handshake method',
            3 => 'Bad request',
            4 => 'Forbidden',
            5 => 'Unsupported protocol version',
        ], array_combine(
            array_map(static fn (ServerError $e): int => $e->value, ServerError::cases()),
            array_map(static fn (ServerError $e): string => $e->message(), ServerError::cases()),
        ));
    }

    public function testOnlyForbiddenIs403(): void
    {
        foreach (ServerError::cases() as $error) {
            self::assertSame($error === ServerError::Forbidden ? 403 : 400, $error->httpStatus());
        }
    }

    public function testBodyIsTheJsonErrorObject(): void
    {
        self::assertSame('{"code":1,"message":"Session ID unknown"}', ServerError::UnknownSid->body());
    }
}
