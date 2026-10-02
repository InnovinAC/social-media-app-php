<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\Middleware;
use Phpvin\Middleware\ThrottleRequests;
use Phpvin\RateLimit\RateLimiter;

/**
 * Five failed sign-in attempts per email per IP, then a fifteen minute wait.
 *
 * Keyed on the email as well as the IP so one attacker cannot lock every
 * account from a single address, and so spraying one password across many
 * accounts still gets throttled per account.
 *
 * SessionController clears the counter on a successful sign-in, so the limit
 * only ever counts failures.
 */
final class ThrottleLogin implements Middleware
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    private readonly ThrottleRequests $throttle;

    public function __construct(RateLimiter $limiter)
    {
        $this->throttle = new ThrottleRequests(
            $limiter,
            maxAttempts: self::MAX_ATTEMPTS,
            decaySeconds: self::DECAY_SECONDS,
            resolveKey: self::keyFor(...),
        );
    }

    public function process(Request $request, Closure $next): Response
    {
        return $this->throttle->process($request, $next);
    }

    public static function keyFor(Request $request): string
    {
        return 'login|' . strtolower(trim((string) $request->post('email'))) . '|' . $request->ip();
    }
}
