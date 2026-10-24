<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Marcolin\SocketIO\Engine\Request;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Turns a Workerman request into an Engine.IO Request.
 */
final class RequestValues
{
    public static function request(TcpConnection $connection, WorkermanRequest $request): Request
    {
        return new Request(
            $request->path(),
            self::query($request),
            self::headers($request),
            $request->uri(),
            $connection->getRemoteIp(),
            $connection->transport === 'ssl',
        );
    }

    /**
     * @return array<string, string>
     */
    private static function query(WorkermanRequest $request): array
    {
        return self::strings($request->get());
    }

    /**
     * @return array<string, string> lower-case names
     */
    private static function headers(WorkermanRequest $request): array
    {
        return self::strings($request->header());
    }

    /**
     * Keeps the string values; arrays such as "sid[]=x" are dropped.
     *
     * @return array<string, string>
     */
    private static function strings(mixed $values): array
    {
        $strings = [];
        foreach (is_array($values) ? $values : [] as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }
}
