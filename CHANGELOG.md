# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html), with the usual
pre-1.0 caveat that the API may still move.

## [Unreleased]

### Added

- **Query grouping.** `whereGroup()` and `orWhereGroup()` wrap conditions in
  parentheses. See the fix below for why this matters.
- **Query surface.** Joins (`join`, `leftJoin`, `rightJoin`), `groupBy`,
  `having`, `distinct`, `whereNotIn`, `whereBetween`, `pluck`, and the
  aggregates `sum`, `avg`, `min`, `max`.
- **Pagination.** `QueryBuilder::paginate()` returns a `Paginator` that counts,
  slices, and serialises for an API.
- **Model relations.** `hasMany`, `hasOne` and `belongsTo`, with eager loading
  via `Model::query()->with(...)`: one extra query per relation instead of one
  per row.
- **Model casts.** `$casts` for int, float, bool, string, array, json and
  datetime, applied on read and reversed on write.
- **Logging.** `ExceptionHandler` takes an optional PSR-3 logger and records
  every failure with the request attached. `FileLogger` ships so a fresh install
  records its own failures without another package.
- **Uploads.** `UploadedFile`, `Request::file()`/`fileList()`/`hasFile()`, and
  the `file`, `image` and `mimes` validation rules. Types come from sniffing the
  bytes, never the client's filename or `Content-Type`.
- **Custom validation rules.** `Validator::extend()`. This is how `unique` and
  `exists` get added, since the ORM is optional and the framework cannot ship
  them.
- **Security middleware.** `SecurityHeaders` and `ThrottleRequests`, plus a
  file-backed `RateLimiter`. The skeleton throttles login to five attempts per
  email per address.
- **Query counting.** `Connection::queryCount()` and an optional query log, so
  "is this N+1?" is something a test can assert.

### Security

- **Template path traversal (pre-release).** Template names reach an engine
  from application code, and application code builds them out of URL segments;
  rendering `"pages/$slug"` for a `/page/{slug}` route is the obvious way to
  write a CMS. `PhpEngine` resolved names by concatenation, so `../secret/evil`
  left the view directory and was *executed*: a slug became remote code
  execution. `HtmlEngine` had the same flaw for file disclosure. `TemplatePath`
  now contains every bundled engine to its root, rejecting `..` lexically and
  comparing realpaths so a symlink inside the root cannot point out of it.
  Containment is stated on the `Engine` contract, so writing your own engine
  tells you the guarantee it has to keep.
- **Response header injection (pre-release).** `Response::header()` accepted CR
  and LF in values, so `redirect($request->input('next'))` could end the header
  and append another, classically a `Set-Cookie`. Values now reject CR, LF and
  NUL, and names are checked against the RFC 7230 token grammar. PHP's own
  `header()` drops such a call with a warning, which is a silently missing
  header rather than an error and covers only one SAPI path.
- **A threat model.** [SECURITY.md](SECURITY.md) now states, as a table,
  which attack classes the framework closes, which it hands you a tool for,
  and which are yours, including the ones it deliberately does not take on.


### Fixed

- **`orWhere` could escape an ownership filter.** `where(a)->where(b)->orWhere(c)`
  compiles to `(a AND b) OR c`, which is correct SQL and almost never what was
  meant: a scoped query would return every user's rows. The behaviour is
  unchanged and now documented, and `whereGroup()` exists to express the other
  grouping.
- **The dirty check misfired on every MySQL row.** PDO returns every column as a
  string, so `$model->hits = 1` on a row loaded as `'1'` marked the model dirty
  and rewrote untouched columns on save. Comparison now allows for driver
  coercion, and `$casts` makes it exact.
- **`HEAD` returned the full body.** Routing correctly served HEAD from the GET
  handler and then sent the whole rendered page. It now sends the headers,
  including `Content-Length`, and no body.
- **Global middleware skipped error responses.** The exception handler ran
  outside the pipeline, so a 404 or 500 came back without the security headers,
  CORS, or anything else the global stack adds. Routing now happens inside the
  global stack.
- **A broken error template took the error page down with it.** If
  `errors/500.twig` threw while rendering, the exception escaped. It is now
  caught, logged, and replaced by the built-in page.
- **`Container` kept a process-wide static cache**, the exact hidden global
  state the class exists to argue against. Now per-instance.
- **`QueryBuilder::first()` capped the builder it was called on**, so a reused
  query silently returned one row.
- **`asset()` prefixed the app URL**, breaking every asset link when the app was
  reached on another host or port. Assets are relative unless `asset_url` is set.

### Changed

- `psr/log` is now a required dependency, alongside `psr/container`.
- PHPStan level 6 and a PHP-CS-Fixer ruleset run in CI. `composer check` runs
  everything CI runs.

## [0.1.0]

First release. Routing, a PSR-11 container, middleware, validation, an
extensible jQuery layer, pluggable view engines, and an optional Active Record
layer.
