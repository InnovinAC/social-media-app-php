<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Auth;
use Closure;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\Middleware;
use Phpvin\View\ViewFactory;

/**
 * Puts the signed-in user in every template as `user`.
 *
 * Without this, the layout's nav would have to be handed a user by every
 * controller. One named place beats a global helper that templates can reach
 * into on their own.
 */
final class ShareCurrentUser implements Middleware
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ViewFactory $views,
    ) {}

    public function process(Request $request, Closure $next): Response
    {
        $this->views->share('user', $this->auth->user());

        return $next($request);
    }
}
