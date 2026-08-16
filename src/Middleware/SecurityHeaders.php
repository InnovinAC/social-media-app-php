<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

/**
 * Adds the response headers every HTML application should send.
 *
 * Defaults only fill in what the response has not already set, so a controller
 * that needs something different just sets it and wins.
 *
 *     $app->middleware([
 *         new SecurityHeaders(contentSecurityPolicy: "default-src 'self'"),
 *     ]);
 *
 * No CSP is sent unless you ask for one. A policy that breaks the page is
 * worse than no policy, and only you know what your pages load.
 */
final class SecurityHeaders implements Middleware
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $extra           Additional headers, or overrides.
     * @param bool                  $hsts            Send Strict-Transport-Security. HTTPS only;
     *                                               on plain HTTP it does nothing but is a
     *                                               foot-gun waiting for the first TLS deploy.
     */
    public function __construct(
        ?string $contentSecurityPolicy = null,
        string $frameOptions = 'DENY',
        string $referrerPolicy = 'strict-origin-when-cross-origin',
        bool $hsts = false,
        int $hstsMaxAge = 31536000,
        array $extra = [],
    ) {
        $this->headers = array_filter([
            // Stops a browser from second-guessing Content-Type, which is how
            // an uploaded "image" gets executed as script.
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => $frameOptions,
            'Referrer-Policy' => $referrerPolicy,
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => $contentSecurityPolicy,
            'Strict-Transport-Security' => $hsts
                ? "max-age=$hstsMaxAge; includeSubDomains"
                : null,
            ...$extra,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers as $name => $value) {
            if ($response->getHeader($name) === null) {
                $response->header($name, $value);
            }
        }

        return $response;
    }
}
