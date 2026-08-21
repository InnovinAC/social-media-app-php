# Security

## Reporting a vulnerability

Please report security issues privately, not as a public issue.

Use GitHub's [private vulnerability reporting](https://github.com/innovin/phpvin/security/advisories/new),
or email **innovinanuonye@gmail.com** with `phpvin security` in the subject.

Include what you can: the affected version, what an attacker can do, and a
snippet that reproduces it. You will get an acknowledgement within a few days.

phpvin is a one-maintainer project, so please be realistic about response times.
If you need a fix urgently, a pull request alongside the report is welcome.

## Supported versions

Pre-1.0, only the latest tagged release gets fixes.

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

## Threat model

What the framework holds the line on, and where the line stops being ours. A
guarantee nobody wrote down is a guarantee nobody can hold you to, so this
table is meant to be checked against the code rather than believed.

| Attack | Status | How |
| --- | --- | --- |
| SQL injection through values | closed | Always bound, never interpolated. |
| SQL injection through identifiers | closed | `Grammar::quote()` rejects anything but `name` or `table.name` before quoting, so an identifier cannot be built from request data at all. |
| Mass assignment | closed | Opt-in. A model with no `$fillable` accepts nothing. |
| CSRF | closed | `VerifyCsrfToken`, compared with `hash_equals`. |
| Session fixation | closed | The id is regenerated on login. |
| Session/cookie tampering | closed | `EncryptCookies` seals with AEAD; a modified value fails to open rather than decrypting to something chosen. |
| Padding-oracle probing | closed | Every decryption failure returns the same message. |
| Template path traversal | closed | `TemplatePath` contains every bundled engine to its root, lexically *and* by realpath. A `/page/{slug}` route cannot be walked out of the view directory. |
| Response header injection | closed | `Response::header()` rejects CR, LF and NUL in values and non-token names, so a redirect built from input cannot append a second header. |
| PHP object injection via the cache | closed when a key is set | Reading a cache entry means unserialising it, and a gadget chain turns any file-write bug on the box into code execution. `FileStore` authenticates entries with a key derived from the application key, so only bytes this application wrote reach `unserialize()`. Without a key the cache still works and this row does not apply, so set one. |
| Upload path traversal | closed | `store()` passes any caller-supplied name through `basename()` and defaults to a generated one. |
| Upload type confusion | closed | Type comes from sniffing the bytes, never the client's filename or `Content-Type`. |
| Host header poisoning | closed by design | Absolute URLs come from a configured `baseUrl`. The framework never reads `Host` to build a link, so a poisoned one has nothing to poison. |
| Timing attacks on tokens | closed | `hash_equals` for CSRF and any sealed value. |
| Brute force | tool provided | `ThrottleRequests` plus the rate limiter. Yours to apply to the endpoints that need it. |
| XSS | tool provided | Twig escapes by default; the `php` engine gives you `$e()`. Escaping is the template's job and always will be. |
| Clickjacking, MIME sniffing | tool provided | `SecurityHeaders`. CSP is yours, because only you know what your pages load. |
| Open redirect | yours | `redirect()` sends where you tell it. Validate a destination that came from a request before handing it over: use an allowlist, or refuse anything not starting with `/`. |
| Authorisation | yours | The framework authenticates a session; who may touch what is application logic. |
| Denial of service | yours | Body size, execution time and connection limits belong to the web server and PHP-FPM. |

## What the framework does for you

Worth knowing what you are and are not getting.

**Handled by default**

- Values are always bound, never interpolated into SQL, and identifiers are
  validated against a strict pattern before being quoted.
- Mass assignment is opt-in. A model with no `$fillable` accepts nothing.
- `VerifyCsrfToken` rejects state-changing requests without a valid token, and
  compares in constant time.
- Sessions are regenerated on login, closing off session fixation.
- Session cookies are `HttpOnly` and `SameSite=Lax`, set before the cookie is
  issued rather than left to whatever php.ini happens to say.
- Uploads are typed by sniffing their contents, never by the client's filename
  or `Content-Type`, and are stored under a generated name.
- Template names cannot escape the template root, on every bundled engine, so
  rendering `"pages/$slug"` from a route parameter is safe to write.
- Cache entries are authenticated once a key is configured, so a file-write
  bug elsewhere on the box cannot feed the cache a payload to unserialise.
- Header values cannot carry a line break, so a `Location` built from user
  input cannot smuggle a second header.
- 5xx messages and stack traces are withheld from the response unless `debug`
  is on.

**Available, and worth using**

- **`Encrypter`**: authenticated encryption. XChaCha20-Poly1305 through
  libsodium where it exists, AES-256-GCM through OpenSSL otherwise. Every
  payload is encrypted *and* authenticated, so a modified ciphertext fails to
  open rather than decrypting to something an attacker chose. Every failure
  gives the same message, because distinguishable ones are what make a padding
  oracle work.
- **`EncryptCookies`**: a cookie is a value the client can rewrite, so anything
  you set and later trust is an input field with extra steps until it is sealed.
  One that fails to decrypt is dropped rather than passed through, so a forged
  value never reaches application code looking genuine.
- **`SecurityHeaders`**: nosniff, frame options, referrer policy. Add a
  Content-Security-Policy yourself; only you know what your pages load.
- **`ThrottleRequests`**: on login and any other guessable endpoint.
- **`session_cookie.secure` and HSTS**, once you are actually on HTTPS. Both are
  off by default: a secure cookie is never sent over plain HTTP, so defaulting
  them on would silently break every local setup.

**Not provided**

- Password reset flows and email verification. Build them on `Encrypter` and
  the rate limiter.
- Output escaping is your template engine's job. Twig escapes by default; the
  `php` engine gives you `$e()` and expects you to use it.

## The encryption key

There is deliberately no default. A key that ships with the framework is a key
every installation shares, which is the same as having none.

```bash
./phpvin key:generate
```

Replacing a live key makes every value sealed with the old one unreadable, so
the command refuses unless you pass `--force`.

## Please do not

Run `debug` on in production. It puts exception messages and stack traces in the
browser.

## Past advisories

None yet. Two issues were found and closed during pre-release hardening, before
any tagged version existed, and are listed here because a security page with
nothing on it tells you less than one that shows its work:

- **Template path traversal.** `PhpEngine` resolved names by concatenation, so
  a template name taken from a URL could leave the view directory and be
  executed. Closed in `e91f952`, along with the same flaw in `HtmlEngine`.
- **Response header injection.** `Response::header()` accepted CRLF in values.
  Closed in `e91f952`.
- **Unauthenticated cache payloads.** `FileStore` handed stored bytes straight
  to `unserialize()`, so any file-write primitive elsewhere could be escalated
  to code execution through a gadget chain. Entries are now authenticated with
  a derived key.
