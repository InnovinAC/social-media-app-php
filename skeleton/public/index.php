<?php

declare(strict_types=1);

use App\Middleware\ShareCurrentUser;
use Dotenv\Dotenv;
use Phpvin\Application;
use Phpvin\Middleware\SecurityHeaders;
use Phpvin\Middleware\UnobtrusiveJavaScript;
use Phpvin\Middleware\VerifyCsrfToken;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();

$app = new Application($root, require $root . '/config.php');

// The stack every request passes through, outermost first.
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

$app->run();
