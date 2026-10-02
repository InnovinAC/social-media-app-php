# Configuration

[← back to the README](../README.md)

Configuration is one array passed to `Application`. There is no config
directory to hunt through and no cache to clear.

```php
$app = new Application(__DIR__, require __DIR__ . '/config.php');
```

Read it back with dot notation:

```php
$app->config('database.driver');
$app->config('mail.from', 'default@example.com');
```

## Everything, with defaults

```php
return [
    // Puts exception messages and stack traces in the browser. Never in production.
    'debug' => false,

    // Origin for absolute(): emails, canonical links.
    'base_url' => '',

    // Origin for asset(). Empty means relative paths, which survive being
    // reached on a different host or port. Set it only for a CDN.
    'asset_url' => '',

    // Sessions cost a file write per request. Off for a stateless API.
    'session' => true,

    // Boot-time wiring. [] for no persistence; list your own to plug in
    // a different ORM.
    'providers' => [
        Phpvin\Database\ActiveRecordProvider::class,
    ],

    // null disables the database entirely.
    'database' => [
        'driver'   => 'sqlite',        // sqlite | mysql | pgsql
        'database' => __DIR__ . '/database/app.sqlite',
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'username' => null,
        'password' => null,
        'charset'  => 'utf8mb4',
        'migrations' => __DIR__ . '/database/migrations',
    ],

    'views' => [
        'engine' => 'twig',            // twig | php | html | none | Engine | Closure
        'path'   => __DIR__ . '/resources/views',
        'cache'  => __DIR__ . '/storage/views',   // false to recompile every request
    ],

    // Omit and nothing is recorded. Bind your own PSR-3 logger over
    // LoggerInterface to use Monolog or anything else.
    'log' => [
        'path'  => __DIR__ . '/storage/logs/app.log',
        'level' => 'info',             // debug | info | notice | warning | error | …
    ],

    'rate_limit' => [
        'path' => __DIR__ . '/storage/rate-limit',
    ],
];
```

The merge is shallow: a key you supply replaces the whole block, so give a
complete `views` array rather than just the one entry you wanted to change.

## Environments

Nothing in the framework reads `.env`. The skeleton loads one with
`vlucas/phpdotenv` and reads `$_ENV` in `config.php`, which keeps the mapping
from environment to configuration in a file you can see:

```php
'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
```

Use whatever you like instead; the config array is plain PHP.

## Three shapes

**JSON API**: no templating, no session, no ORM:

```php
['views' => ['engine' => 'none'], 'session' => false, 'providers' => []]
```

Errors come back as JSON whether or not the client sent an `Accept` header.

**Server-rendered site:**

```php
['views' => ['engine' => 'twig', 'path' => __DIR__ . '/resources/views']]
```

**Both at once**: an array return becomes JSON, a string or `Response` becomes
HTML. Nothing needs configuring.

## The container

Everything the framework builds is registered in a PSR-11 container and can be
replaced. Bind over any of these in a [service provider](../README.md#persistence):

| Binding | Default |
| --- | --- |
| `Router`, `UrlGenerator` | shared per request |
| `Session` | shared, PHP's session |
| `Validator` | shared |
| `ViewFactory` | from `views.engine` |
| `ExceptionHandler` | debug-aware, logging |
| `LoggerInterface` | `FileLogger`, or `NullLogger` with no `log.path` |
| `RateLimiter` | file-backed |
| `Connection`, `Migrator` | from `database`, via `ActiveRecordProvider` |

Classes the container can figure out on its own do not need registering. A
binding is for when the default (a fresh instance each time) is wrong.
