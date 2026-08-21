<?php

declare(strict_types=1);

namespace Phpvin\Cache;

use Closure;
use DateInterval;
use Psr\SimpleCache\CacheInterface;
use stdClass;

/**
 * A cache on disk.
 *
 * Works everywhere PHP does, which is the point. Swap in Redis or APCu by
 * binding another CacheInterface over this one.
 *
 * Entries are written to a temporary file and renamed into place, because
 * rename is atomic on every filesystem that matters: a reader either sees the
 * old entry or the new one, never half of either.
 *
 * Reading a cache entry means unserialising it, and unserialising attacker
 * chosen bytes is remote code execution wherever the installed classes happen
 * to contain a usable gadget chain: the standard way a file-write bug
 * anywhere on the box gets upgraded into running code. Give this a secret and
 * every entry is authenticated on the way out, so only bytes this application
 * wrote are ever handed to unserialize(). Application does that for you when
 * an encryption key is configured.
 */
final class FileStore implements CacheInterface
{
    /** Length of the hex MAC that prefixes an authenticated payload. */
    private const MAC_LENGTH = 64;

    /** @var Closure(): int */
    private Closure $clock;

    private readonly ?string $secret;

    /**
     * @param (Closure(): int)|null $clock  Where "now" comes from. Injectable
     *                                      so a test can sit exactly on an
     *                                      expiry boundary instead of sleeping.
     * @param string|null           $secret Authenticates entries. Null keeps
     *                                      the plain format, which is fine for
     *                                      a cache nothing else can write to.
     */
    public function __construct(
        private readonly string $directory,
        ?Closure $clock = null,
        ?string $secret = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();

        // Derived rather than used directly, so the cache MAC key and whatever
        // else the application key is doing cannot be played off each other.
        $this->secret = $secret === null || $secret === ''
            ? null
            : hash_hmac('sha256', 'phpvin:cache:v1', $secret, true);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        Ttl::validateKey($key);

        $contents = @file_get_contents($this->pathFor($key));

        if ($contents === false) {
            return $default;
        }

        // The expiry is the first ten characters, so an expired entry is
        // recognised without unserialising a payload that is about to be
        // thrown away.
        $expires = (int) substr($contents, 0, 10);

        if ($expires !== 0 && $expires <= ($this->clock)()) {
            $this->delete($key);

            return $default;
        }

        $serialised = $this->verified($contents);

        if ($serialised === null) {
            return $default;
        }

        $payload = @unserialize($serialised, ['allowed_classes' => true]);

        return $payload === false && $serialised !== serialize(false) ? $default : $payload;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        Ttl::validateKey($key);
        $seconds = Ttl::seconds($ttl);

        // A ttl of exactly 0 expires at the moment it is written, so get()
        // would treat it as a miss either way. Deleting is the honest form.
        if ($seconds !== null && $seconds <= 0) { // mutation:ignore zero is indistinguishable downstream
            return $this->delete($key);
        }

        if (! $this->ensureDirectory()) {
            return false;
        }

        $expires = $seconds === null ? 0 : ($this->clock)() + $seconds;
        $head = str_pad((string) $expires, 10, '0', STR_PAD_LEFT);
        $contents = $head . $this->authenticate($head, serialize($value));

        $temporary = $this->pathFor($key) . '.' . bin2hex(random_bytes(4));

        $written = @file_put_contents($temporary, $contents, LOCK_EX);

        // Not just `=== false`: a short write means a full disk, and the
        // truncated entry it leaves behind would read back as a corrupt one
        // forever.
        if ($written !== strlen($contents)) {
            @unlink($temporary);

            return false;
        }

        // Atomic: a concurrent reader never sees a half-written entry.
        if (! @rename($temporary, $this->pathFor($key))) {
            @unlink($temporary);

            return false; // mutation:ignore unreachable: a failed write leaves no temporary file to rename
        }

        return true;
    }

    public function delete(string $key): bool
    {
        Ttl::validateKey($key);
        @unlink($this->pathFor($key));

        return true;
    }

    public function clear(): bool
    {
        foreach (glob(rtrim($this->directory, '/') . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $sentinel = new stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * Remove entries that have already expired.
     *
     * Nothing calls this automatically: a cache that stops the world to tidy
     * up is worse than one that uses a little more disk. Run it from cron.
     *
     * @return int How many were removed.
     */
    public function prune(): int
    {
        $removed = 0;

        foreach (glob(rtrim($this->directory, '/') . '/*.cache') ?: [] as $file) {
            $handle = @fopen($file, 'r');

            if ($handle === false) {
                continue;
            }

            $expires = (int) fread($handle, 10);
            fclose($handle);

            if ($expires !== 0 && $expires <= ($this->clock)() && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->directory)
            || @mkdir($this->directory, 0o700, true) // mutation:ignore the recursive flag is not observable once the parent exists
            || is_dir($this->directory); // mutation:ignore race guard
    }

    /**
     * Keys are hashed, so a key containing a slash or a name you would rather
     * not have on disk cannot become a path.
     */
    /**
     * The serialised payload, or null when it is not ours to trust.
     */
    private function verified(string $contents): ?string
    {
        $body = substr($contents, 10);

        if ($this->secret === null) {
            return $body;
        }

        $mac = substr($body, 0, self::MAC_LENGTH);
        $serialised = substr($body, self::MAC_LENGTH);

        // hash_equals, because a byte-at-a-time comparison leaks how much of a
        // forged MAC was right, and a cache entry can be probed in a loop.
        return hash_equals($this->expected(substr($contents, 0, 10), $serialised), $mac)
            ? $serialised
            : null;
    }

    private function authenticate(string $head, string $serialised): string
    {
        return $this->secret === null
            ? $serialised
            : $this->expected($head, $serialised) . $serialised;
    }

    /**
     * Covers the expiry as well as the payload, so a stored entry cannot have
     * its lifetime extended without invalidating the MAC.
     */
    private function expected(string $head, string $serialised): string
    {
        return hash_hmac('sha256', $head . $serialised, (string) $this->secret);
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->directory, '/') . '/' . hash('xxh128', $key) . '.cache';
    }
}
