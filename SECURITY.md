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

## What the framework does for you

Worth knowing what you are and are not getting.

**Handled by default**

- Values are always bound, never interpolated into SQL, and identifiers are
  validated against a strict pattern before being quoted.
- Mass assignment is opt-in. A model with no `$fillable` accepts nothing.
- `VerifyCsrfToken` rejects state-changing requests without a valid token, and
  compares in constant time.
- Sessions are regenerated on login, closing off session fixation.
- Uploads are typed by sniffing their contents, never by the client's filename
  or `Content-Type`, and are stored under a generated name.
- 5xx messages and stack traces are withheld from the response unless `debug`
  is on.

**Opt in, and you should**

- `SecurityHeaders` middleware, nosniff, frame options, referrer policy. Add a
  Content-Security-Policy yourself; only you know what your pages load.
- `ThrottleRequests` middleware on login and any other guessable endpoint.
- HSTS, once you are actually serving over HTTPS.

- **Authenticated encryption** via `Encrypter`, XChaCha20-Poly1305 where
  libsodium is available, AES-256-GCM otherwise. Every payload is encrypted and
  authenticated, so a modified ciphertext fails to open rather than decrypting
  to something an attacker chose. Every failure gives the same message, because
  distinguishable ones are what make a padding oracle work.
- **Session cookies** are `HttpOnly` and `SameSite=Lax` out of the box, set
  before the cookie is issued rather than left to php.ini.

**Opt in, and you should**

- `EncryptCookies` middleware. A cookie is a value the client can rewrite;
  anything you set and later trust has to be sealed. A cookie that fails to
  decrypt is dropped rather than passed through, so a forged value never
  reaches application code looking genuine.
- `session_cookie.secure` once you are on HTTPS. It is off by default because
  a secure cookie is never sent over plain HTTP, and defaulting it on would
  silently break every local setup.

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
