<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Engine;

use Closure;
use InvalidArgumentException;

/**
 * CORS headers for the HTTP long-polling responses, as the cors package the reference uses writes them.
 *
 * An origin that isn't allowed gets no Access-Control-Allow-Origin; the browser then blocks the response.
 */
final class Cors
{
    /** @var list<string>|(Closure(string, Request): bool)|null null allows any origin */
    private readonly array|Closure|null $allowed;

    /**
     * @param string|list<string>|(Closure(string $origin, Request $request): bool) $origin "*" for any origin, the
     *        allowed ones, or a function that decides for each request
     * @param bool $credentials lets browsers send cookies; needs a list or a function, since they reject "*" with it
     */
    public function __construct(string|array|Closure $origin = '*', public readonly bool $credentials = false)
    {
        if (is_string($origin) && $origin !== '*') {
            throw new InvalidArgumentException('The CORS origin must be "*", a list of origins, or a Closure');
        }
        if ($origin === '*' && $credentials) {
            throw new InvalidArgumentException(
                'Browsers reject the CORS origin "*" with credentials: give a list of origins or a Closure',
            );
        }
        $this->allowed = $origin === '*' ? null : $origin;
    }

    /**
     * @return array<string, string>
     */
    public function headers(Request $request): array
    {
        $headers = $this->originHeaders($request);
        if ($this->credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    /**
     * Headers for the answer to an OPTIONS preflight request.
     *
     * @return array<string, string>
     */
    public function preflightHeaders(Request $request): array
    {
        $headers = $this->headers($request) + ['Access-Control-Allow-Methods' => 'GET, POST, OPTIONS'];
        $requested = $request->header('Access-Control-Request-Headers');
        if ($requested !== null && $requested !== '') {
            $headers['Access-Control-Allow-Headers'] = $requested;
            $headers['Vary'] = isset($headers['Vary'])
                ? $headers['Vary'] . ', Access-Control-Request-Headers'
                : 'Access-Control-Request-Headers';
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function originHeaders(Request $request): array
    {
        if ($this->allowed === null) {
            return ['Access-Control-Allow-Origin' => '*'];
        }
        $origin = $request->header('Origin');
        if ($origin !== null && $this->allows($origin, $request)) {
            return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
        }

        return ['Vary' => 'Origin'];
    }

    private function allows(string $origin, Request $request): bool
    {
        if ($this->allowed instanceof Closure) {
            return ($this->allowed)($origin, $request) === true;
        }

        return in_array($origin, $this->allowed ?? [], true);
    }
}
