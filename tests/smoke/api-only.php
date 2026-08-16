<?php

declare(strict_types=1);

/**
 * Proves the core runs with no optional packages installed.
 *
 * Run under `composer install --no-dev`, where Twig, phpdotenv and PHPUnit are
 * all absent. If the framework ever grows a hard dependency on one of them,
 * this fails in CI rather than in somebody's project.
 *
 *     php tests/smoke/api-only.php
 */

use Phpvin\Application;
use Phpvin\Http\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = 0;

function check(string $description, bool $passed): void
{
    global $failures;

    if (! $passed) {
        $failures++;
    }

    printf("  %s  %s\n", $passed ? 'ok  ' : 'FAIL', $description);
}

printf(
    "Twig installed: %s   (the point of this script is that it does not matter)\n\n",
    class_exists(Twig\Environment::class) ? 'yes' : 'no',
);

$app = new Application(__DIR__, [
    'views' => ['engine' => 'none'],
    'session' => false,
    'providers' => [],
]);

$app->router()->get('/health', fn (): array => ['status' => 'ok']);
$app->router()->get('/users/{id}', fn (int $id): array => ['id' => $id], where: ['id' => '\d+']);

$health = $app->handle(Request::create('GET', '/health'));
check('a JSON route responds 200', $health->status() === 200);
check('the payload is encoded', $health->body() === '{"status":"ok"}');
check('the content type is JSON', str_contains((string) $health->getHeader('content-type'), 'application/json'));

$user = $app->handle(Request::create('GET', '/users/42'));
check('route parameters are cast to their declared type', $user->body() === '{"id":42}');

$missing = $app->handle(Request::create('GET', '/nope'));
check('a miss is a JSON 404 without an Accept header', $missing->status() === 404);
check('the 404 body is JSON', json_decode($missing->body(), true)['status'] === 404);

$constrained = $app->handle(Request::create('GET', '/users/abc'));
check('a where constraint still applies', $constrained->status() === 404);

echo $failures === 0
    ? "\nAPI-only smoke test passed.\n"
    : "\n$failures check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
