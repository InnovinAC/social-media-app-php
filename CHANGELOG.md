# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html), with the usual
pre-1.0 caveat that the API may still move.

## [0.1.0] - 2026-08-21

First release. Routing, a PSR-11 container, middleware, validation, an
extensible jQuery layer, pluggable view engines, an optional Active Record
layer, a console, cache, events and authenticated encryption.

Everything below was written before any version existed, so it is all part of
this release rather than a history of changes to one. The security entries are
kept in full: the point of a first changelog is not to look uneventful.

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

### Added

- **`bin/fuzz`.** Hostile input at fourteen entry points: routing, request
  parsing, URL generation, template resolution, response headers, validation,
  identifier quoting, cache keys and the encrypter. Each is held to one rule:
  reject whatever you like, but reject it *deliberately*. A documented
  exception passes; a `TypeError` or `Error` is a value that reached code
  assuming it could not exist. Seeded, so a failure replays exactly, and run
  in CI with a fresh seed each time so it keeps exploring rather than
  re-testing what it already covered.
- **Exhaustive tamper detection.** The encryption tests no longer only round
  trip. They walk every bit of every sealed payload (nonce, tag, ciphertext),
  flip it, and require all of them to fail to open, on both backends. Plus
  truncation at every length, cipher downgrade, and a wrong key.

### Added

- **`bin/differential`.** Generates query shapes and asks every driver the same
  question, requiring one answer. A disagreement is a grammar bug by
  definition: the promise the framework makes is that swapping the driver does
  not change the result, so the oracle is agreement rather than a hand-written
  expectation. The dangerous queries are the ones nobody sat down to write,
  like the left join with a group by and an offset that compiles differently on one
  engine. Those are found by generating them, not by waiting for a report.
- **`orderBy(..., nulls: 'first'|'last')`.** Engines disagree about where a
  null sorts and disagree silently: SQLite and MySQL treat null as the smallest
  value, Postgres as the largest, so the same `desc` sort puts nulls at
  opposite ends. Add a `limit` and the same query returns different rows on
  different drivers. Postgres and SQLite get `NULLS FIRST`/`NULLS LAST`; MySQL,
  which has neither, gets the equivalent `IS NULL` sort key. Found by
  `bin/differential`.

### Added

- **`bin/memory`.** Measures what a booted application retains per request
  rather than how fast it serves one. Under mod_php or FPM a leak is invisible,
  because the interpreter tears everything down every time; under FrankenPHP,
  RoadRunner, Swoole or a queue worker the process boots once and serves for
  days, and a few hundred retained bytes per request is a restart loop. Growth
  is measured after a warm-up, since the first few hundred requests are
  one-time allocations. Every path currently retains nothing.

### Fixed

- **The development tools could mislead each other.** `bin/mutate` rewrites
  `src/` hundreds of times per run, so anything else reading the tree meanwhile
  reads a deliberate lie and reports it as a finding. Both failure modes
  happened here, to the person who had written the warning against them: a
  concurrent `bin/fuzz` reported a `TypeError` that did not exist, and
  `bin/package-check` packaged a mutated source and failed. A mutation run now
  takes a lock, and `bin/fuzz`, `bin/differential`, `bin/memory`,
  `bin/package-check` and a second `bin/mutate` all refuse while it is held,
  saying why. A lock left by a killed run is reclaimed automatically.


- **A failed render leaked its layout.** `PhpEngine` pushes a template's layout
  request before evaluating it and takes it off after, so a template that threw
  in between left the request on the stack with nothing to ever remove it.
  Rendering is correct either way (the depth comparison is relative), but a
  worker that boots once and serves for days keeps one more entry for every
  render that errors, and template errors are ordinary rather than exceptional.
  Measured at 382MB per million failed renders. The stack now unwinds to the
  depth it was entered at.


- **Concurrent deploys could run the same migration more than once.** Every
  process asked which migrations were pending, all got the same answer, and all
  acted on it. Measured: four simultaneous runs against MySQL, three of which
  died on `CREATE TABLE ... already exists`. In a rolling deploy, that is three
  instances that never came up. Postgres happened to survive on lock timing
  rather than by design. A run now holds an advisory lock for its whole
  duration, because the race is between reading `pending()` and acting on it,
  so a per-migration lock would cover nothing. The lock is session-scoped
  (`GET_LOCK` on MySQL, `pg_try_advisory_lock` on Postgres), so a process
  killed mid-migration drops its connection and the lock goes with it; a lock
  row in a table would outlive the crash and need a human to clear it. Waiting
  is bounded, so a stuck holder fails the deploy with a readable message
  instead of hanging. SQLite has no advisory lock and is left unlocked, which
  suits how it is deployed.
- **The rate limiter lost attempts to a race.** `hit()` read the count, added
  one and wrote it back, with no lock held across the three steps. Requests
  arriving together each read the same number and each stored the same
  increment, so the rest simply vanished: a test firing 60 concurrent attempts
  recorded 6. A limit of five attempts a minute therefore admitted roughly
  fifty to anyone willing to open connections in parallel, which is precisely
  what someone guessing passwords does. The whole read-modify-write is now held
  under one exclusive lock, opened with `c+` so taking the lock cannot truncate
  the count it is protecting. `tests/ConcurrencyTest.php` spawns real
  processes, because a single-process suite runs operations to completion one
  at a time and that is the one condition under which this bug cannot happen.
- **A `hasOne` with two matching rows could load differently eagerly and
  lazily.** Two rows matching a `hasOne` is a data problem rather than a shape
  the relation supports, but it happens, and the two loading paths then asked
  different questions: eager took the first row of a `WHERE key IN (...)`
  covering every parent, lazy the first of a `WHERE key = ?` for one. Neither
  was ordered, so nothing obliged an engine to answer them consistently. It
  happened to, on this data, until a rebuilt index or a different plan changed
  its mind. Both sides now take the lowest primary key, which makes "which one"
  a decision rather than an accident.


- **Aggregates over a grouped query rejected a qualified column.** A grouped
  select is wrapped in a subquery aliased `grouped`, and the aggregate kept the
  original table on the column, naming a table that is no longer in scope, so
  `->groupBy(...)->max('posts.views')` failed on SQLite, MySQL and Postgres
  alike. Broken everywhere at once rather than on one driver, which is why the
  cross-driver suite never caught it and a generated query did.
- **`bin/mutate` could leave a mutant in the working tree.** A mutant is a
  deliberate edit to a real file, and the restore had no `finally` and no
  signal handling despite a comment claiming it always ran. A run stopped part
  way (Ctrl-C, a CI timeout, a throw from the runner) left the source quietly
  wrong, so later test runs failed for a reason that was not in git and the
  failure got attributed to whatever was edited next. This actually happened
  during development: a run killed at a ten-minute limit left an inverted
  comparison behind, the next run mutated it *back* to the correct code and
  reported that as a surviving mutant, and the score was an artifact of
  corrupted source. Restores now run in a `finally`, on `SIGINT`/`SIGTERM`/
  `SIGHUP`, and at shutdown; and every file is hashed before the run and
  verified after, so a restore that silently does not happen is a loud
  `exit 2` rather than a wrong number.

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
- **Authenticated cache entries (pre-release).** Reading a cache entry means
  unserialising it, and unserialising bytes an attacker chose is code execution
  wherever the installed classes contain a usable gadget. This is the standard way a
  file-write bug anywhere on a box gets upgraded into running code, and a
  routine finding against other frameworks' cache directories. `FileStore` now
  authenticates every entry with a key derived from the application key, so
  only bytes the application wrote reach `unserialize()`. The MAC covers the
  expiry too, so a stored entry cannot be given a longer life than it was
  written with. Without a configured key the format is unchanged and the cache
  behaves exactly as before.
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
