<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use Closure;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\Middleware;

/**
 * Keeps signed-in users away from the login and register pages.
 */
final class RequireGuest implements Middleware
{
    public function __construct(private readonly Auth $auth) {}

    public function process(Request $request, Closure $next): Response
    {
        return $this->auth->check()
            ? new RedirectResponse('/dashboard')
            : $next($request);
    }
}
