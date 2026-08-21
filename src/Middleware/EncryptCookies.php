<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Crypto\DecryptionFailed;
use Phpvin\Crypto\Encrypter;
use Phpvin\Http\Request;
use Phpvin\Http\Response;

/**
 * Encrypts cookies on the way out and decrypts them on the way in.
 *
 * A cookie is a value the client can rewrite. Anything you set and later trust
 * (a preference, a tenant id, a "remember me" token) has to be sealed, or it
 * is an input field with extra steps.
 *
 *     $app->middleware([new EncryptCookies($encrypter)]);
 *
 * A cookie that fails to decrypt is dropped rather than passed through, so a
 * forged value never reaches application code as though it were genuine.
 *
 * PHP's own session cookie is exempt by default: the session id is a lookup
 * key the handler manages, and encrypting it would break session_start().
 */
final class EncryptCookies implements Middleware
{
    /** @var list<string> */
    private array $except;

    /**
     * @param list<string> $except Cookie names to leave alone.
     */
    public function __construct(
        private readonly Encrypter $encrypter,
        array $except = [],
    ) {
        $this->except = [...$except, session_name() ?: 'PHPSESSID'];
    }

    public function process(Request $request, Closure $next): Response
    {
        $response = $next($this->withDecryptedCookies($request));

        foreach ($response->cookies() as $index => $cookie) {
            if ($this->isExempt($cookie['name']) || $cookie['value'] === '') {
                continue;
            }

            $response->replaceCookie($index, $this->encrypter->encrypt($cookie['value']));
        }

        return $response;
    }

    private function withDecryptedCookies(Request $request): Request
    {
        $decrypted = [];

        foreach ($request->cookies as $name => $value) {
            if ($this->isExempt($name)) {
                $decrypted[$name] = $value;

                continue;
            }

            try {
                $decrypted[$name] = $this->encrypter->decrypt($value);
            } catch (DecryptionFailed) {
                // Forged, truncated, or left over from a previous key. Either
                // way it is not ours, so the application never sees it.
                continue;
            }
        }

        return $request->withCookies($decrypted);
    }

    private function isExempt(string $name): bool
    {
        return in_array($name, $this->except, true); // mutation:ignore strict flag is equivalent for an array of string literals
    }
}
