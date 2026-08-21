# phpvin

A small PHP framework you can read in one sitting.

Routing, a PSR-11 container, real middleware, validation, an extensible jQuery
layer and an optional Active Record layer. No facades, no global helpers, no
build step. Works as a JSON API, a server-rendered site, or one process doing
both.

```php
$routes->get('/posts/{id}', [PostController::class, 'show'],
    as:      'posts.show',
    where:   ['id' => '\d+'],
    through: [RequireUser::class],
);
```

## The idea

Most PHP frameworks make you choose between "does everything, and you'll never
read it" and "does nothing, bring your own everything". phpvin sits in between:
enough to build a real application, small enough that when something goes wrong
you can open the file and see why.

The design rule is **explicit over magic**:

- **No facades and no global helpers.** No `view()`, no `route()`, no `auth()`.
  If a class needs something it asks for it in the constructor, and the
  container hands it over. This is the difference between code you can test and
  code you can only run.
- **No magic strings.** Route handlers are `[PostController::class, 'show']`,
  never `'PostController@show'`. Your editor can follow it; a rename that misses
  one is a parse error, not a 500 in production.
- **Named arguments over fluent chains.** One call declares one route, with
  everything about it visible in that call.
- **Mass assignment is opt-in.** A model with no `$fillable` accepts nothing. A
  stray `is_admin` field in a form post cannot reach the database.
- **No hidden global state.** One documented exception: Active Record's static
  connection, which the pattern cannot work without.

Where this overlaps with prior art, it overlaps honestly. `find`, `save` and
`where` are the vocabulary of the Active Record pattern, not any one library's
property. The parts worth copying were copied on purpose; the parts that make
frameworks hard to reason about were not.

## Install

```bash
composer require innovin/phpvin
```

Required dependencies: `psr/container` and `psr/log`. Add `twig/twig` for the
Twig engine and `vlucas/phpdotenv` for `.env` files if you want them.

The smallest thing that works:

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
| [Testing](docs/testing.md) | the toolkit for testing applications built on phpvin |

## What's in the box

| | |
| --- | --- |
| `Container` | PSR-11, constructor autowiring, cycle detection |
| `Router` | named-argument registration, groups, constraints, 404/405 |
| `Pipeline` | middleware that wraps both directions |
| `Request` / `Response` | immutable request, `_method` override, JSON, redirects, uploads |
| `Session` | flash messages, CSRF tokens, detachable for tests |
| `Validator` | 20 rules, open for extension, no dependencies |
| `Model` / `QueryBuilder` | optional Active Record over PDO, relations, eager loading |
| `Migrator` / `Paginator` | transactional migrations, paginated results |
| `Engine` | Twig, plain PHP, static HTML, or your own |
| `phpvin.js` | declarative AJAX, custom behaviours, server-driven commands |
| `SecurityHeaders` / `ThrottleRequests` | the defaults an app should not have to write |
| `FileLogger` | PSR-3, so failures are recorded out of the box |
| `ApplicationTestCase` | test your app through the real stack, with CSRF and sessions handled |

## Working on the framework itself

This repository holds two packages:

| Path | Package | |
| --- | --- | --- |
| `.` | `innovin/phpvin` | the framework |
| `skeleton/` | `innovin/phpvin-skeleton` | an application that consumes it |

The skeleton resolves the framework through a Composer path repository, so edits
to `src/` are live in the running app with no reinstall.

```bash
git clone https://github.com/innovin/phpvin.git
cd phpvin
make install
make migrate
make serve
```

A working site at http://localhost:8000 (home page, register, sign in, a
protected dashboard with AJAX notes, and a JSON endpoint), backed by SQLite,
with nothing else to install.

```bash
composer check        # style, PHPStan level 6, tests: everything CI runs
make package-check    # install it as a real dependency and boot it
```

`package-check` is the one worth knowing about. A path repository is a symlink,
which hides a whole class of packaging mistakes: a file left out of the
distribution, a path that only resolves because the source happens to sit next
door. It builds the distributable the way `.gitattributes` says it ships,
installs it **copied** into a throwaway application, and boots that.

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Testing

458 tests. They run against SQLite by default, nothing to install, and the
same suite runs against MySQL 8 and Postgres 16, because the interesting bugs
only exist on a database you did not develop on. Postgres rejects the backticks
MySQL requires; MySQL commits implicitly on DDL and hands back every column as a
string. All three found real bugs here.

```bash
make db-up && make test-drivers
```

The suite is also mutation tested: `bin/mutate` breaks the source one edit at a
time and checks the tests notice. **317 mutants, 100% killed** across the three
drivers. That number is the one worth trusting; a passing suite only proves the
tests ran.

CI runs the matrix on PHP 8.2, 8.3 and 8.4, plus PHPStan level 6, code style, a
packaging check, mutation testing, and a `--no-dev` job that boots an API-only
app with no optional package installed.

### Testing your own application

Because nothing reaches for a global, a request is just a function call, and
the framework ships the toolkit that builds on it:

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

No HTTP server, no superglobals, no browser driver. The CSRF token is attached
for you, the session persists between requests, and a failing assertion prints
the body it actually got. See [docs/testing.md](docs/testing.md).

## Requirements

PHP 8.2+ and `ext-pdo`.

## Releasing

The version comes from git tags; there is no `version` field in
`composer.json`.

```bash
git tag v0.1.0 && git push --tags
```

Then submit the repository once at [packagist.org](https://packagist.org) and
enable the GitHub hook; later tags publish themselves. `.gitattributes` keeps
the download to `src/`, `resources/`, the manifest and the licence.

To use the skeleton as a starting point once published, delete the
`repositories` block from `skeleton/composer.json`.

## Status

Early. The API may still move before 1.0. It is tested and it works, but it has
not been through a production year yet, treat it accordingly.
[CHANGELOG.md](CHANGELOG.md) records what has moved.

## License

MIT. See [LICENSE](LICENSE).
