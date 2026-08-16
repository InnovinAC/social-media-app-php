<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\ThrottleLogin;
use App\Support\Auth;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\RateLimit\RateLimiter;
use Phpvin\Validation\Validator;
use Phpvin\View\ViewFactory;

/**
 * Signing in and out. One controller per resource, and the resource here is
 * the session itself.
 */
final class SessionController
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Validator $validator,
        private readonly Auth $auth,
        private readonly RateLimiter $limiter,
    ) {}

    public function create(): Response
    {
        return $this->views->response('auth/login');
    }

    public function store(Request $request): Response
    {
        $credentials = $this->validator->validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = $this->auth->attempt($credentials['email'], $credentials['password']);

        if ($user === null) {
            // Deliberately vague: saying which half was wrong tells an
            // attacker whether the address is registered.
            return (new RedirectResponse('/login', 303))->withErrors(
                $request->session(),
                ['email' => ['Those credentials do not match our records.']],
                ['email' => $credentials['email']],
            );
        }

        // Only failures should count towards the lockout.
        $this->limiter->clear(ThrottleLogin::keyFor($request));

        $intended = $request->session()->get('intended', '/dashboard');
        $request->session()->forget('intended');

        return (new RedirectResponse(is_string($intended) ? $intended : '/dashboard', 303))
            ->with($request->session(), 'success', 'Welcome back.');
    }

    public function destroy(Request $request): Response
    {
        $this->auth->logout();

        return (new RedirectResponse('/', 303))
            ->with($request->session(), 'success', 'Signed out.');
    }
}
