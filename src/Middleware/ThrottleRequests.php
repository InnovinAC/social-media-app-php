<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Http\JsonResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\RateLimit\RateLimiter;

/**
 * Caps how often a client may hit a route.
 *
 *     $routes->post('/login', [SessionController::class, 'store'],
 *         through: [new ThrottleRequests($limiter, maxAttempts: 5, decaySeconds: 900)],
 *     );
 *
 * Keyed by client IP and path by default. Pass $resolveKey to key on something
 * else: the submitted email on a login form, an API token, a tenant id.
 */
final class ThrottleRequests implements Middleware
{
    /** @var (Closure(Request): string)|null */
    private $resolveKey;

    /**
     * @param (Closure(Request): string)|null $resolveKey
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxAttempts = 60,
        private readonly int $decaySeconds = 60,
        ?Closure $resolveKey = null,
    ) {
        $this->resolveKey = $resolveKey;
    }

    public function process(Request $request, Closure $next): Response
    {
        $key = $this->resolveKey === null
            ? $request->ip() . '|' . $request->path
            : ($this->resolveKey)($request);

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            return $this->tooMany($request, $this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $this->decaySeconds);

        return $next($request)
            ->header('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->header('X-RateLimit-Remaining', (string) $this->limiter->remaining($key, $this->maxAttempts));
    }

    private function tooMany(Request $request, int $retryAfter): Response
    {
        $message = sprintf('Too many attempts. Try again in %d second%s.', $retryAfter, $retryAfter === 1 ? '' : 's');

        $response = $request->wantsJson()
            ? new JsonResponse(['message' => $message, 'status' => 429], 429)
            : Response::text($message, 429);

        return $response
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->header('X-RateLimit-Remaining', '0');
    }
}
