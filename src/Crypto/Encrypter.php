<?php

declare(strict_types=1);

namespace Phpvin\Crypto;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Authenticated encryption.
 *
 * Every payload is encrypted *and* authenticated, so a modified ciphertext
 * fails to decrypt rather than decrypting to something an attacker chose. Two
 * encryptions of the same value never look alike, because the nonce is random.
 *
 *     $key = Encrypter::generateKey();     // store this, never commit it
 *     $encrypter = Encrypter::fromKey($key);
 *
 *     $sealed = $encrypter->encrypt('user 7');
 *     $encrypter->decrypt($sealed);        // 'user 7', or DecryptionFailed
 *
 * XChaCha20-Poly1305 through libsodium where it is available, AES-256-GCM
 * through OpenSSL otherwise. Both are AEAD constructions; the cipher used is
 * recorded in the payload, so a value sealed on one host still opens on
 * another with a different extension mix.
 */
final class Encrypter
{
    private const SODIUM = 'x1';

    private const OPENSSL = 'a1';

    private const KEY_BYTES = 32;

    /**
     * @param string      $key    32 raw bytes. Use generateKey()/fromKey()
     *                            rather than inventing one; a passphrase is
     *                            not a key.
     * @param string|null $cipher Null picks the best available. Pin it to
     *                            'openssl' when a fleet has mixed extensions
     *                            and values must open everywhere.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $key,
        private readonly ?string $cipher = null,
    ) {
        if ($cipher !== null && ! in_array($cipher, [self::SODIUM, self::OPENSSL], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException(
                "Unknown cipher [$cipher]. Use 'x1' (XChaCha20-Poly1305), 'a1' (AES-256-GCM), or null to choose.",
            );
        }

        if (strlen($this->key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'An encryption key must be exactly %d bytes, got %d. Generate one with Encrypter::generateKey().',
                self::KEY_BYTES,
                strlen($this->key),
            ));
        }
    }

    /**
     * A new key, base64 encoded so it survives a .env file.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * Build from a base64 key, with or without a `base64:` prefix.
     */
    public static function fromKey(#[SensitiveParameter] string $key, ?string $cipher = null): self
    {
        $raw = base64_decode(str_starts_with($key, 'base64:') ? substr($key, 7) : $key, true);

        if ($raw === false) {
            throw new InvalidArgumentException('The encryption key is not valid base64.');
        }

        return new self($raw, $cipher);
    }

    /** The cipher label this instance seals with. */
    public function cipher(): string
    {
        return $this->useSodium() ? self::SODIUM : self::OPENSSL;
    }

    /**
     * Seal a value.
     *
     * @return string URL-safe base64, so it is usable in a cookie or a query
     *                string without further encoding.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        if ($this->useSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

            // The cipher label is authenticated as associated data, so nobody
            // can downgrade a payload by rewriting its prefix.
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                self::SODIUM,
                $nonce,
                $this->key,
            );

            return self::SODIUM . '.' . $this->encode($nonce . $ciphertext);
        }

        $nonce = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::OPENSSL);

        if ($ciphertext === false) { // mutation:ignore openssl_encrypt only fails on a bad cipher or key, both validated already
            throw DecryptionFailed::payload();
        }

        return self::OPENSSL . '.' . $this->encode($nonce . $tag . $ciphertext);
    }

    /**
     * Open a sealed value.
     *
     * @throws DecryptionFailed when the payload was tampered with, truncated,
     *                          sealed with another key, or is not ours at all
     */
    public function decrypt(string $payload): string
    {
        [$cipher, $encoded] = array_pad(explode('.', $payload, 2), 2, null);

        if ($encoded === null || $encoded === '') {
            throw DecryptionFailed::payload();
        }

        $raw = $this->decode($encoded);

        if ($raw === null) {
            throw DecryptionFailed::payload();
        }

        $plaintext = match ($cipher) {
            self::SODIUM => $this->openSodium($raw),
            self::OPENSSL => $this->openOpenssl($raw),
            default => false,
        };

        if ($plaintext === false) {
            throw DecryptionFailed::payload();
        }

        return $plaintext;
    }

    /**
     * Whether a string looks like something this class produced.
     *
     * A cheap shape check, not a guarantee. Only decrypt() can tell you that.
     */
    public static function looksSealed(string $value): bool
    {
        return str_starts_with($value, self::SODIUM . '.') || str_starts_with($value, self::OPENSSL . '.');
    }

    private function openSodium(string $raw): string|false
    {
        if (! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            // Sealed on a host with libsodium, opened on one without.
            return false; // mutation:ignore only reachable where the extension is missing
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // Strictly less than: sodium throws on a short nonce, so it must not
        // see one. A payload of exactly the nonce length has an empty
        // ciphertext, which the primitive rejects on its own.
        if (strlen($raw) < $nonceLength) { // mutation:ignore the equality case is rejected downstream
            return false;
        }

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            self::SODIUM,
            substr($raw, 0, $nonceLength),
            $this->key,
        );
    }

    private function openOpenssl(string $raw): string|false
    {
        // No length guard needed: unlike sodium, openssl_decrypt() returns
        // false for a short nonce rather than throwing.
        return openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            self::OPENSSL,
        );
    }

    private function useSodium(): bool
    {
        if ($this->cipher === self::OPENSSL) {
            return false;
        }

        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    /** URL-safe base64, unpadded. */
    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): ?string
    {
        // Strict, so stray characters are a failure rather than being
        // silently stripped into a shorter payload.
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true); // mutation:ignore either way the payload fails to open

        return $raw === false ? null : $raw;
    }
}
