<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use Closure;
use Phpvin\Http\HttpException;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\Middleware;

/**
 * Blocks anyone who is not signed in.
 */
final class RequireUser implements Middleware
{
    public function __construct(private readonly Auth $auth) {}

    public function process(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            throw HttpException::unauthorised('You must be signed in to do that.');
        }

        // Remember where they were headed so login can send them back.
        $request->session()->put('intended', $request->path);

        return (new RedirectResponse('/login'))
            ->with($request->session(), 'error', 'Please sign in first.');
    }
}
