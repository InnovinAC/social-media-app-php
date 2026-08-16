<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\NotesController;
use App\Controllers\RegistrationController;
use App\Controllers\SessionController;
use App\Middleware\RequireGuest;
use App\Middleware\RequireUser;
use App\Middleware\ThrottleLogin;
use Phpvin\Asset\Assets;
use Phpvin\Routing\Router;

/**
 * Routes are configured with named arguments, so everything about a route is
 * visible in the one call that declares it.
 *
 * Handlers are [Class::class, 'method'] pairs, so your editor can jump straight
 * to them, and a rename that misses one is a parse error rather than a 500 in
 * production.
 */
return function (Router $routes): void {
    // Serves the bundled phpvin.js. Nothing to copy, no build step.
    Assets::register($routes);

    $routes->get('/', [HomeController::class, 'index'], as: 'home');

    $routes->group(through: [RequireGuest::class], define: function (Router $routes): void {
        $routes->get('/login', [SessionController::class, 'create'], as: 'login');
        $routes->post('/login', [SessionController::class, 'store'], through: [ThrottleLogin::class]);

        $routes->get('/register', [RegistrationController::class, 'create'], as: 'register');
        $routes->post('/register', [RegistrationController::class, 'store']);
    });

    $routes->group(through: [RequireUser::class], define: function (Router $routes): void {
        $routes->get('/dashboard', [HomeController::class, 'dashboard'], as: 'dashboard');
        $routes->post('/logout', [SessionController::class, 'destroy'], as: 'logout');

        // Posted normally without JavaScript, over AJAX with it. Same actions.
        $routes->post('/notes', [NotesController::class, 'store'], as: 'notes.store');
        $routes->delete('/notes/{id}', [NotesController::class, 'destroy'],
            as: 'notes.destroy',
            where: ['id' => '\d+'],
        );
    });

    // A JSON endpoint in the same application as the HTML pages above. An
    // array return becomes a JSON response; nothing else is needed.
    $routes->group(prefix: '/api', as: 'api.', define: function (Router $routes): void {
        $routes->get('/health', fn (): array => ['status' => 'ok'], as: 'health');
    });
};
