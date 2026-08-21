<?php

declare(strict_types=1);

namespace Phpvin\Http;

/**
 * A thin, injectable wrapper over PHP's session.
 *
 * The array backing it can be swapped, which is the only reason tests can
 * touch session state without a live PHP session.
 */
class Session
{
    private const FLASH_NEW = '_flash_new';
    private const FLASH_OLD = '_flash_old';
    private const CSRF = '_csrf_token';

    /** @var array<string, mixed>|null Null means "use $_SESSION". */
    private ?array $store = null;

    private bool $started = false;

    /**
     * Cookie settings applied when a real session is started.
     *
     * The defaults are the safe ones: unreadable from JavaScript, not sent on
     * cross-site requests, and, once you set `secure`, never over plain HTTP.
     * A session cookie readable by script is one XSS away from a stolen
     * account, so this is not left to whatever php.ini happens to say.
     *
     * @var array<string, mixed>
     */
    private array $cookieOptions = [
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => false,
        'path' => '/',
        'lifetime' => 0,
    ];

    /**
     * @param array<string, mixed>|null $store Pass an array to run detached
     *                                         from PHP's session handler.
     */
    public function __construct(?array $store = null)
    {
        if ($store !== null) {
            $this->store = $store;
            $this->started = true;
            $this->ageFlash();
        }
    }

    /**
     * Override the session cookie settings. Call before start().
     *
     * @param array<string, mixed> $options Any of httponly, samesite, secure,
     *                                      path, domain, lifetime.
     */
    public function useCookieOptions(array $options): void
    {
        $this->cookieOptions = [...$this->cookieOptions, ...$options];
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->store === null && session_status() === PHP_SESSION_NONE) {
            // Set before the cookie is issued; afterwards it has no effect.
            session_set_cookie_params($this->cookieOptions);
            session_start();
        }

        $this->started = true;
        $this->ageFlash();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->write($key, $value);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function forget(string $key): void
    {
        if ($this->store !== null) {
            unset($this->store[$key]);

            return;
        }

        unset($_SESSION[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->store ?? $_SESSION ?? [];
    }

    /**
     * Stash a value for the next request only.
     */
    public function flash(string $key, mixed $value): void
    {
        $new = $this->get(self::FLASH_NEW, []);
        $new[$key] = $value;
        $this->write(self::FLASH_NEW, $new);
    }

    /**
     * Read a value flashed during the previous request.
     */
    public function flashed(string $key, mixed $default = null): mixed
    {
        return $this->get(self::FLASH_OLD, [])[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allFlashed(): array
    {
        return $this->get(self::FLASH_OLD, []);
    }

    /**
     * The CSRF token for this session, generated on first access.
     */
    public function csrfToken(): string
    {
        $token = $this->get(self::CSRF);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->write(self::CSRF, $token);
        }

        return $token;
    }

    /**
     * Compare a submitted token against the session token in constant time.
     */
    public function verifyCsrf(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $known = $this->get(self::CSRF);

        return is_string($known) && $known !== '' && hash_equals($known, $token);
    }

    /**
     * Issue a new session id, keeping the data. Call this on login to close
     * off session fixation.
     */
    public function regenerate(): void
    {
        if ($this->store === null && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if ($this->store !== null) {
            $this->store = [];

            return;
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Promote this request's flash bag to "old" so it survives exactly one
     * more request, and clear whatever was already old.
     */
    private function ageFlash(): void
    {
        $this->write(self::FLASH_OLD, $this->get(self::FLASH_NEW, []));
        $this->write(self::FLASH_NEW, []);
    }

    private function write(string $key, mixed $value): void
    {
        if ($this->store !== null) {
            $this->store[$key] = $value;

            return;
        }

        $_SESSION[$key] = $value;
    }
}
