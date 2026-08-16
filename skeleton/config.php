<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Everything the framework needs is in this one array. There is no config
 * directory to hunt through and no cache to clear.
 */

$root = __DIR__;

return [
    // Boot-time wiring. Drop ActiveRecordProvider and list your own to use a
    // different ORM; the framework core never references either.
    'providers' => [
        Phpvin\Database\ActiveRecordProvider::class,
        App\Providers\AppProvider::class,
    ],

    // Sessions cost a file write per request. Turn them off for a stateless
    // API and Request::session() will say so if anything still asks.
    'session' => true,

    // Never leave this on in production: it puts stack traces in the browser.
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),

    'base_url' => $_ENV['APP_URL'] ?? '',

    // Left empty, asset() emits relative paths, which survive being reached on
    // a different host or port. Set it only when assets live on a CDN.
    'asset_url' => $_ENV['ASSET_URL'] ?? '',

    'database' => [
        'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
        // `?:` not `??`: an empty DB_DATABASE= line should fall back too.
        'database' => ($_ENV['DB_DATABASE'] ?? '') ?: $root . '/database/app.sqlite',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'username' => $_ENV['DB_USERNAME'] ?? null,
        'password' => $_ENV['DB_PASSWORD'] ?? null,
    ],

    // Unhandled 5xx failures land here. Without it they vanish.
    'log' => [
        'path' => $_ENV['LOG_PATH'] ?? $root . '/storage/logs/app.log',
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
    ],

    'rate_limit' => [
        'path' => $root . '/storage/rate-limit',
    ],

    'views' => [
        // 'twig' | 'php' | 'html' | 'none', or your own Engine instance.
        'engine' => $_ENV['VIEW_ENGINE'] ?? 'twig',
        'path' => $root . '/resources/views',

        // Compiling templates on every request is only tolerable in dev.
        'cache' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
            ? false
            : $root . '/storage/views',
    ],
];
