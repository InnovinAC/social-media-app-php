<?php

declare(strict_types=1);

namespace Phpvin\Cache;

use Closure;
use DateInterval;
use Psr\SimpleCache\CacheInterface;
use stdClass;

/**
 * A cache that lives for one request.
 *
 * Useful on its own for memoising within a request, and the obvious choice in
 * tests: no files to clean up and no clock to wait on.
 */
final class ArrayStore implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $entries = [];

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Where "now" comes from. Injectable
     *                                     so a test can sit exactly on an
     *                                     expiry boundary instead of sleeping.
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return $default;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= ($this->clock)()) {
            unset($this->entries[$key]);

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $seconds = Ttl::seconds($ttl);

        // A ttl of exactly 0 expires at the moment it is written, so get()
        // would treat it as a miss either way. Deleting is the honest form.
        if ($seconds !== null && $seconds <= 0) { // mutation:ignore zero is indistinguishable downstream
            // A non-positive TTL means "already expired", which is a delete.
            unset($this->entries[$key]);

            return true;
        }

        $this->entries[$key] = [
            'value' => $value,
            'expires' => $seconds === null ? null : ($this->clock)() + $seconds,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

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
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
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

    private function validateKey(string $key): void
    {
        Ttl::validateKey($key);
    }
}
