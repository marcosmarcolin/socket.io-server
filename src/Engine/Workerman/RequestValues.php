<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine\Workerman;

use Workerman\Protocols\Http\Request;

/**
 * Query and header values of a Workerman request, as plain strings.
 */
final class RequestValues
{
    /**
     * @return array<string, string>
     */
    public static function query(Request $request): array
    {
        return self::strings($request->get());
    }

    /**
     * @return array<string, string> lower-case names
     */
    public static function headers(Request $request): array
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
