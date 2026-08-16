# Middleware

[← back to the README](../README.md)

Middleware wraps the request *and* the response:

```php
final class Timer implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        return $next($request)->header('X-Duration', (string) (microtime(true) - $started));
    }
}
```

Return early without calling `$next` to short-circuit the stack.

## Where it runs

```php
$app->middleware([SecurityHeaders::class, VerifyCsrfToken::class]);       // global
$routes->post('/notes', $handler, through: [RequireUser::class]);          // per route
$routes->group(through: [RequireAdmin::class], define: fn ($r) => ...);    // per group
```

**Global middleware wraps routing as well as the handler.** That matters: a 404
or a 500 still comes back with your security headers on it, because the
exception handler runs inside the global stack rather than outside it. Route
middleware only wraps its own handler, so it never sees a 404.

Layers run outermost-first on the way in and reverse on the way out:

```
SecurityHeaders  ->  VerifyCsrfToken  ->  RequireUser  ->  handler
                 <-                   <-               <-
```

## What ships

### VerifyCsrfToken

Rejects state-changing requests without a valid token, comparing in constant
time. Reads `_token` from the body or `X-CSRF-Token` from the headers; the
latter is what phpvin.js sends automatically.

```php
new VerifyCsrfToken(except: ['/webhooks/*']);
```

Reads (`GET`, `HEAD`, `OPTIONS`) pass untouched. A failure is a `419`.

### SecurityHeaders

```php
new SecurityHeaders(
    contentSecurityPolicy: "default-src 'self'",   // opt-in
    frameOptions: 'DENY',
    referrerPolicy: 'strict-origin-when-cross-origin',
    hsts: true,                                    // HTTPS only
);
```

Sends `X-Content-Type-Options: nosniff`, frame options, referrer policy,
`X-Permitted-Cross-Domain-Policies` and `Cross-Origin-Opener-Policy` by default.
It only fills in what the response has not already set, so a controller that
needs something different just sets it.

No CSP is sent unless you ask for one. A policy that breaks the page is worse
than no policy, and only you know what your pages load. Same for HSTS: on plain
HTTP it does nothing but wait to bite the first TLS deploy.

### ThrottleRequests

```php
new ThrottleRequests($limiter, maxAttempts: 5, decaySeconds: 900);
```

Keyed by client IP and path by default. Pass `resolveKey` to key on something
else, such as the submitted email on a login form, an API token, a tenant id:

```php
new ThrottleRequests($limiter,
    maxAttempts: 5,
    decaySeconds: 900,
    resolveKey: fn (Request $r): string => 'login|' . $r->post('email') . '|' . $r->ip(),
);
```

Adds `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a block is a `429` with
`Retry-After`. Keys are hashed before they touch disk, so an email address never
becomes a filename.

`RateLimiter` itself is file-backed, because that works everywhere PHP does.
Bind your own Redis or APCu version over it in the container.

### UnobtrusiveJavaScript

The server half of [the jQuery layer](jquery.md). Turns a redirect made during
an AJAX request into a `204` carrying the destination in a header, so one
controller action serves both JS and no-JS clients.

## Errors

Anything thrown becomes a response via `ExceptionHandler`.

- `HttpException` keeps its status; anything else is a 500.
- 4xx messages describe the client's mistake and are shown. 5xx messages
  describe yours and are withheld unless `debug` is on.
- A JSON client gets JSON. An API-only app gets JSON regardless.
- A `ValidationException` redirects back with errors and old input flashed, or
  returns 422 JSON.
- Failures are logged with the request attached: 5xx at error level, 4xx at
  info so bot traffic does not drown the log.
- Drop an `errors/404.twig` or `errors/500.twig` in your views to replace the
  built-in page. If yours throws, that is caught and logged too.
