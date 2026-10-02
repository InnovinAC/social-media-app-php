# phpvin

A small PHP framework.

Routing, a PSR-11 container, middleware, validation, a console, cache, events,
authenticated encryption, a jQuery layer and an optional Active Record ORM. No
facades, no global helpers, no build step. Use it for a JSON API, a
server-rendered site, or both in one app.

```php
$routes->get('/posts/{id}', [PostController::class, 'show'],
    as:      'posts.show',
    where:   ['id' => '\d+'],
    through: [RequireUser::class],
);
```

## Design

phpvin aims to be big enough to build a real application with and small enough
to read when something goes wrong. The main rule is explicit over magic:

- **No facades or global helpers.** There is no `view()`, `route()` or
  `auth()`. Classes get what they need through the constructor.
- **No magic strings.** Route handlers are `[PostController::class, 'show']`,
  not `'PostController@show'`, so your editor and static analysis can follow
  them.
- **Named arguments instead of fluent chains.** Each route is declared in one
  call.
- **Mass assignment is opt-in.** A model with no `$fillable` accepts nothing.
- **No hidden global state.** The one exception is Active Record's static
  connection, which is documented where it lives.

## Install

```bash
composer require innovin/phpvin
```

The only required dependencies are four PSR interface packages:
`psr/container`, `psr/log`, `psr/simple-cache` and `psr/event-dispatcher`. Add
`twig/twig` for Twig templates and `vlucas/phpdotenv` for `.env` files if you
want them.

A minimal app:

```php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

use Phpvin\Application;

$app = new Application(dirname(__DIR__), [
    'views'     => ['engine' => 'none'],
    'session'   => false,
    'providers' => [],
]);

$app->router()->get('/', fn (): array => ['hello' => 'world']);

$app->run();
```

A fuller front controller:

```php
$app = new Application(__DIR__, require __DIR__ . '/config.php');

$app->middleware([
    new SecurityHeaders(),
    VerifyCsrfToken::class,
    UnobtrusiveJavaScript::class,
]);

(require __DIR__ . '/routes/web.php')($app->router());

$app->run();
```

## Documentation

| | |
| --- | --- |
| [Routing](docs/routing.md) | verbs, placeholders, groups, named URLs |
| [Database](docs/database.md) | models, casts, relations, queries, migrations |
| [Views](docs/views.md) | Twig, plain PHP, static HTML, or your own engine |
| [Validation](docs/validation.md) | rules, custom rules, file uploads |
| [Middleware](docs/middleware.md) | the pipeline, CSRF, security headers, throttling, errors |
| [The jQuery layer](docs/jquery.md) | attributes, behaviours, server-driven commands |
| [Configuration](docs/configuration.md) | every option, and the container |
| [Testing](docs/testing.md) | testing applications built on phpvin |
| [Console](docs/console.md) | migrate, route:list, make, and writing your own |
| [Cache, events, files](docs/cache-events-files.md) | PSR-16 cache, PSR-14 events, downloads and streaming |

## What's included

| | |
| --- | --- |
| `Container` | PSR-11, constructor autowiring, cycle detection |
| `Router` | named-argument registration, groups, constraints, 404/405 |
| `Pipeline` | middleware that wraps both directions |
| `Request` / `Response` | immutable request, `_method` override, JSON, redirects, uploads |
| `Session` | flash messages, CSRF tokens, detachable for tests |
| `Validator` | 20 rules, extendable, no dependencies |
| `Model` / `QueryBuilder` | optional Active Record over PDO, relations, eager loading |
| `Migrator` / `Paginator` | transactional migrations, paginated results |
| `Engine` | Twig, plain PHP, static HTML, or your own |
| `phpvin.js` | declarative AJAX, custom behaviours, server-driven commands |
| `SecurityHeaders` / `ThrottleRequests` | security headers and rate limiting |
| `FileLogger` | PSR-3 logging out of the box |
| `ApplicationTestCase` | test your app through the full stack, with CSRF and sessions handled |
| `Console` | `migrate`, `route:list`, `make`, `about`, `serve`, plus your own |
| `Encrypter` / `EncryptCookies` | authenticated encryption and encrypted cookies |
| `CacheInterface` | PSR-16, file and array stores |
| `Dispatcher` | PSR-14 events, listeners built lazily |
| `FileResponse` / `StreamedResponse` | downloads and streamed output without buffering |

## Development

This repository holds two packages:

| Path | Package | |
| --- | --- | --- |
| `.` | `innovin/phpvin` | the framework |
| `skeleton/` | `innovin/phpvin-skeleton` | an example application that uses it |

The skeleton loads the framework through a Composer path repository, so changes
to `src/` show up in the app without reinstalling.

```bash
git clone https://github.com/innovin/phpvin.git
cd phpvin
make install
make migrate
make serve
```

That gives you a site at http://localhost:8000 with a home page, registration,
sign-in, a dashboard with AJAX notes and a JSON endpoint, running on SQLite.

```bash
composer check        # style, PHPStan level 6 and tests
make package-check    # install as a real dependency and boot it
```

`package-check` installs a copy of the package (not a symlink) into a temporary
app and boots it. This catches files missing from the distribution, which a
path repository would hide.

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Testing

Tests run on SQLite by default. The same suite also runs against MySQL 8 and
Postgres 16:

```bash
make db-up && make test-drivers
```

Other checks:

- `make mutate` runs mutation testing with `bin/mutate`. Every mutant is
  currently killed on all three databases.
- `make fuzz` sends malformed input (control bytes, overlong UTF-8, path
  traversal, integer boundaries, serialized objects) to the framework's entry
  points and fails on anything other than a documented exception. Each run
  prints its seed so failures can be replayed.
- `make differential` generates queries, runs them on all three databases and
  fails if the results differ.
- `make memory` checks that handling a request doesn't leave memory behind,
  which matters under long-running servers like FrankenPHP, RoadRunner or
  Swoole.

CI runs the suite on PHP 8.2, 8.3 and 8.4 against all three databases, along
with PHPStan, code style, the packaging check, mutation testing, fuzzing, the
differential and memory checks, and a `--no-dev` job that boots an API-only
app with no optional packages.

The skeleton has its own tests, written with the framework's testing toolkit.

### Testing your own application

```php
final class NotesTest extends ApplicationTestCase
{
    public function test_a_note_can_be_added(): void
    {
        $this->withSession(['user_id' => 1])
            ->post('/notes', ['body' => 'hello'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('success');
    }
}
```

No HTTP server, superglobals or browser driver needed. The CSRF token is added
for you, the session carries over between requests, and failed assertions show
the response body. See [docs/testing.md](docs/testing.md).

## Performance

`make bench` measures the hot paths. Route matching stays flat as the number of
routes grows:

| Routes | Worst-case match |
| --- | --- |
| 50 | 1.4 µs |
| 500 | 1.4 µs |
| 3,000 | 1.4 µs |

Static paths are looked up in a hash map. Routes with placeholders are grouped
by segment count and leading literal segment, so `/users/7` never gets checked
against `/posts/{id}`. Registration order still decides which route wins.

A full request through `handle()` (routing, middleware, container resolution,
response) takes about 4 µs with no I/O.

## Requirements

PHP 8.2+ and `ext-pdo`.

## Releasing

Versions come from git tags; `composer.json` has no `version` field.

```bash
bin/release-check v0.1.0
git tag v0.1.0 && git push --tags
```

Then submit the repository to [Packagist](https://packagist.org) once and
enable the GitHub hook so later tags publish automatically. `.gitattributes`
limits the download to `src/`, `resources/`, the manifest and the licence.

To use the skeleton as a starting point after publishing, remove the
`repositories` block from `skeleton/composer.json`.

## Status

Early. The API may change before 1.0; [CHANGELOG.md](CHANGELOG.md) records what
changes. The threat model is in [SECURITY.md](SECURITY.md).

phpvin is well tested but hasn't been used in production for long. If you need
a framework with years of production use behind it, use Symfony or Laravel.

## License

MIT. See [LICENSE](LICENSE).
