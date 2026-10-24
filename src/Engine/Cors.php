<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use InvalidArgumentException;

/**
 * CORS headers for the HTTP long-polling responses.
 *
 * An origin outside the list gets no Access-Control-Allow-Origin; the browser then blocks the response.
 */
final class Cors
{
    /** @var list<string>|null null allows any origin */
    private readonly ?array $allowed;

    /**
     * @param string|list<string> $origin "*" for any origin, or the allowed ones
     */
    public function __construct(string|array $origin = '*')
    {
        if (is_string($origin) && $origin !== '*') {
            throw new InvalidArgumentException('The CORS origin must be "*" or a list of origins');
        }
        $this->allowed = is_array($origin) ? $origin : null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(?string $requestOrigin): array
    {
        if ($this->allowed === null) {
            return ['Access-Control-Allow-Origin' => '*'];
        }
        if ($requestOrigin !== null && in_array($requestOrigin, $this->allowed, true)) {
            return ['Access-Control-Allow-Origin' => $requestOrigin, 'Vary' => 'Origin'];
        }

        return ['Vary' => 'Origin'];
    }

    /**
     * Headers for the answer to an OPTIONS preflight request.
     *
     * @return array<string, string>
     */
    public function preflightHeaders(?string $requestOrigin, ?string $requestHeaders): array
    {
        $headers = $this->headers($requestOrigin) + ['Access-Control-Allow-Methods' => 'GET, POST, OPTIONS'];
        if ($requestHeaders !== null && $requestHeaders !== '') {
            $headers['Access-Control-Allow-Headers'] = $requestHeaders;
            $headers['Vary'] = isset($headers['Vary'])
                ? $headers['Vary'] . ', Access-Control-Request-Headers'
                : 'Access-Control-Request-Headers';
        }

        return $headers;
    }
}
