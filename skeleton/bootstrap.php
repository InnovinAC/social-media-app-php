<?php

declare(strict_types=1);

/**
 * Builds the application.
 *
 * Shared by the front controller, the console and the test suite, so all three
 * exercise the same wiring rather than three copies that drift apart.
 */

use App\Middleware\ShareCurrentUser;
use Dotenv\Dotenv;
use Phpvin\Application;
use Phpvin\Middleware\SecurityHeaders;
use Phpvin\Middleware\UnobtrusiveJavaScript;
use Phpvin\Middleware\VerifyCsrfToken;

$root = __DIR__;

if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

$app = new Application($root, require $root . '/config.php');

$app->middleware([
    // Outermost, so the headers are on error responses too.
    new SecurityHeaders(
        hsts: filter_var($_ENV['APP_HTTPS'] ?? false, FILTER_VALIDATE_BOOL),
    ),

    VerifyCsrfToken::class,

    // Turns a redirect made during an AJAX request into a header phpvin.js
    // can act on, so one controller action serves both JS and no-JS clients.
    UnobtrusiveJavaScript::class,

    ShareCurrentUser::class,
]);

(require $root . '/routes/web.php')($app->router());

return $app;
