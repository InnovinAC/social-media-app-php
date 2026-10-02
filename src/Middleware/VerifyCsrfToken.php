<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Http\HttpException;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

/**
 * Rejects state-changing requests that do not carry the session's CSRF token.
 *
 * Put it in the global stack. Templates get the token from the session and
 * render it as a hidden `_token` field; AJAX clients may send `X-CSRF-Token`.
 */
class VerifyCsrfToken implements Middleware
{
    /** @var list<string> Paths exempt from the check, e.g. inbound webhooks. */
    protected array $except = [];

    /**
     * @param list<string> $except Path prefixes to skip, `*` allowed at the end.
     */
    public function __construct(array $except = [])
    {
        $this->except = $except;
    }

    public function process(Request $request, Closure $next): Response
    {
        if ($request->isReading() || $this->isExempt($request->path)) {
            return $next($request);
        }

        $token = $request->post('_token') ?? $request->header('x-csrf-token');

        if (! $request->hasSession() || ! $request->session()->verifyCsrf(is_string($token) ? $token : null)) {
            throw HttpException::pageExpired(
                'This form has expired or the security token was missing. Please reload the page and try again.',
            );
        }

        return $next($request);
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->except as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            if (str_ends_with($pattern, '*') && str_starts_with($path, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }
}
