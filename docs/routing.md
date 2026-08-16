# Routing

[← back to the README](../README.md)

Routes are configured with named arguments rather than a fluent chain. One call
is one route, with everything about it visible in that call.

```php
$routes->get('/posts/{id}', [PostController::class, 'show'],
    as:      'posts.show',
    where:   ['id' => '\d+'],
    through: [RequireUser::class],
);
```

Handlers are always `[Controller::class, 'method']` or a closure. There is
deliberately no `'Controller@show'` string form: a class constant is navigable
in an editor, survives a rename, and fails at parse time rather than at request
time.

## Verbs

`get`, `post`, `put`, `patch`, `delete`, `options`, and `on([...verbs])` for one
handler across several. A `HEAD` request is served by the matching `GET` route,
with the body stripped from the response.

```php
$routes->on(['GET', 'POST'], '/search', [SearchController::class, 'handle']);
```

## Placeholders

| Syntax | Matches |
| --- | --- |
| `{id}` | one path segment |
| `{slug?}` | one segment, or nothing |
| `{path*}` | the rest of the path, slashes included |

Constrain them with `where`. Values are URL-decoded after matching, so `%2F`
cannot invent a new segment.

```php
$routes->get('/docs/{path*}', [DocsController::class, 'show']);
$routes->get('/archive/{year}/{month?}', [ArchiveController::class, 'index'],
    where: ['year' => '\d{4}', 'month' => '\d{2}'],
);
```

A controller that type-hints `int $id` gets an int. Route parameters arrive as
strings and are cast to the declared scalar type.

## Groups

Groups share a prefix, a middleware stack, and a name prefix. They nest, and
their state is restored even if the closure throws.

```php
$routes->group(prefix: '/admin', through: [RequireAdmin::class], as: 'admin.',
    define: function (Router $routes): void {
        $routes->get('/users', [UserController::class, 'index'], as: 'users');
        // -> /admin/users, named admin.users, behind RequireAdmin
    },
);
```

## Named routes and URLs

Inject `UrlGenerator`. There is no global `route()` helper, though the Twig and
PHP engines expose one to templates, wired up for you.

```php
$urls->route('posts.show', ['id' => 7]);              // /posts/7
$urls->route('posts.show', ['id' => 7, 'ref' => 'a']); // /posts/7?ref=a
$urls->absolute('posts.show', ['id' => 7]);           // https://…/posts/7
$urls->asset('js/app.js');                            // /js/app.js
```

Leftover parameters become a query string. A missing required parameter throws
rather than producing a broken link. Two routes sharing a name is an error at
registration.

## What a handler may return

| Return | Becomes |
| --- | --- |
| `Response` | itself |
| `Commands` | a command list for phpvin.js |
| `string` | an HTML response |
| `null` | `204 No Content` |
| anything else | a JSON response |

## Misses

A path that matches nothing is a 404. A path that matches but with the wrong
verb is a 405 naming the verbs that would have worked. Both go through the
[exception handler](middleware.md), so both come back as HTML or JSON depending
on what the client asked for, and both still carry your global middleware's
headers.
