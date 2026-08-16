<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

/**
 * Middleware wraps the request *and* the response.
 *
 * Call $next to continue down the stack and you get the Response back, which
 * means work can happen on the way in, on the way out, or both:
 *
 *     public function process(Request $request, Closure $next): Response
 *     {
 *         $started = microtime(true);
 *         $response = $next($request);
 *
 *         return $response->header('X-Duration', (string) (microtime(true) - $started));
 *     }
 *
 * Return early without calling $next to short-circuit the stack.
 */
interface Middleware
{
    public function process(Request $request, Closure $next): Response;
}
