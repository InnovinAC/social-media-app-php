<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use Closure;
use DateInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Cache\ArrayStore;
use Phpvin\Cache\FileStore;
use Phpvin\Cache\InvalidCacheKey;
use Psr\SimpleCache\CacheInterface;
use ReflectionMethod;

/**
 * Both stores, run through the same tests, because "PSR-16 compatible" only
 * means something if the implementations agree with each other.
 */
final class CacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpvin-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function stores(): array
    {
        return ['array' => ['array'], 'file' => ['file']];
    }

    private function store(string $kind, ?Closure $clock = null): CacheInterface
    {
        return $kind === 'array' ? new ArrayStore($clock) : new FileStore($this->directory, $clock);
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_value_comes_back(string $kind): void
    {
        $cache = $this->store($kind);

        $this->assertTrue($cache->set('key', 'value'));
        $this->assertSame('value', $cache->get('key'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_missing_key_returns_the_default(string $kind): void
    {
        $cache = $this->store($kind);

        $this->assertNull($cache->get('nope'));
        $this->assertSame('fallback', $cache->get('nope', 'fallback'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function every_kind_of_value_survives(string $kind): void
    {
        $cache = $this->store($kind);

        foreach ([
            'string' => 'text',
            'int' => 42,
            'float' => 1.5,
            'true' => true,
            'false' => false,
            'null' => null,
            'array' => ['nested' => ['deep' => true]],
        ] as $key => $value) {
            $cache->set($key, $value);
            $this->assertSame($value, $cache->get($key, 'MISSING'), $key);
        }
    }

    #[Test]
    #[DataProvider('stores')]
    public function false_is_a_value_not_a_miss(string $kind): void
    {
        // The classic cache bug: storing false and reading it back as absent.
        $cache = $this->store($kind);
        $cache->set('flag', false);

        $this->assertFalse($cache->get('flag', 'MISSING'));
        $this->assertTrue($cache->has('flag'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function null_is_a_value_not_a_miss(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('nothing', null);

        $this->assertTrue($cache->has('nothing'));
        $this->assertNull($cache->get('nothing', 'MISSING'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function has_reports_absence(string $kind): void
    {
        $this->assertFalse($this->store($kind)->has('never-set'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function delete_removes_a_value(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('key', 'value');

        $this->assertTrue($cache->delete('key'));
        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function deleting_something_absent_is_still_a_success(string $kind): void
    {
        $this->assertTrue($this->store($kind)->delete('never-set'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function clear_empties_the_store(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('a', 1);
        $cache->set('b', 2);

        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('a'));
        $this->assertFalse($cache->has('b'));
    }

    // --- expiry --------------------------------------------------------------

    #[Test]
    #[DataProvider('stores')]
    public function a_value_with_no_ttl_does_not_expire(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('forever', 'value');

        $this->assertSame('value', $cache->get('forever'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_expired_value_reads_as_absent(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('brief', 'value', -1);

        $this->assertFalse($cache->has('brief'));
        $this->assertSame('gone', $cache->get('brief', 'gone'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_zero_ttl_stores_nothing(string $kind): void
    {
        $cache = $this->store($kind);
        $cache->set('key', 'value');
        $cache->set('key', 'replacement', 0);

        // Already expired at the moment of writing, so it is a delete.
        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_date_interval_ttl_works_too(string $kind): void
    {
        $cache = $this->store($kind);

        $cache->set('soon', 'value', new DateInterval('PT1H'));
        $this->assertSame('value', $cache->get('soon'));

        $cache->set('past', 'value', DateInterval::createFromDateString('-1 hour'));
        $this->assertFalse($cache->has('past'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_month_long_interval_is_not_guessed_at_thirty_days(string $kind): void
    {
        $cache = $this->store($kind);

        // Converted through a real date, so the length of the actual month is
        // what counts.
        $this->assertTrue($cache->set('long', 'value', new DateInterval('P1M')));
        $this->assertSame('value', $cache->get('long'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_entry_expiring_exactly_now_is_already_gone(string $kind): void
    {
        // The boundary, without sleeping: the clock is frozen and then moved.
        $now = 1_000_000;
        $cache = $this->store($kind, function () use (&$now): int {
            return $now;
        });

        $cache->set('key', 'value', 60);
        $this->assertSame('value', $cache->get('key'));

        $now += 59;
        $this->assertSame('value', $cache->get('key'), 'one second left');

        $now += 1;
        $this->assertSame('gone', $cache->get('key', 'gone'), 'expiring now counts as expired');
    }

    #[Test]
    #[DataProvider('stores')]
    public function setting_a_non_positive_ttl_reports_success(string $kind): void
    {
        // It is a delete, and a delete succeeds.
        $this->assertTrue($this->store($kind)->set('key', 'value', 0));
        $this->assertTrue($this->store($kind)->set('key', 'value', -5));
    }

    // --- bulk ------------------------------------------------------------------

    #[Test]
    #[DataProvider('stores')]
    public function values_can_be_set_and_read_in_bulk(string $kind): void
    {
        $cache = $this->store($kind);

        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        $this->assertSame(['a' => 1, 'b' => 2], (array) $cache->getMultiple(['a', 'b']));
        $this->assertSame(['a' => 1, 'c' => 'gone'], (array) $cache->getMultiple(['a', 'c'], 'gone'));

        $this->assertTrue($cache->deleteMultiple(['a', 'b']));
        $this->assertFalse($cache->has('a'));
    }

    // --- keys --------------------------------------------------------------------

    #[Test]
    #[DataProvider('stores')]
    public function an_empty_key_is_refused(string $kind): void
    {
        $this->expectException(InvalidCacheKey::class);

        $this->store($kind)->set('', 'value');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_reserved_character_in_a_key_is_refused(string $kind): void
    {
        $cache = $this->store($kind);

        foreach (['a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $key) {
            try {
                $cache->get($key);
                $this->fail("Accepted the reserved key [$key]");
            } catch (InvalidCacheKey) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_awkward_but_legal_key_works(string $kind): void
    {
        $cache = $this->store($kind);
        $key = 'user.7|preferences-v2 ünïcode';

        $cache->set($key, 'value');

        $this->assertSame('value', $cache->get($key));
    }

    // --- file store specifics ------------------------------------------------------

    #[Test]
    public function a_key_never_lands_on_disk_in_readable_form(): void
    {
        (new FileStore($this->directory))->set('user.7.secret-preference', 'value');

        $names = array_map('basename', glob($this->directory . '/*') ?: []);

        $this->assertCount(1, $names);
        $this->assertStringNotContainsString('secret-preference', $names[0]);
    }

    #[Test]
    public function pruning_removes_expired_entries_and_leaves_the_rest(): void
    {
        $cache = new FileStore($this->directory);
        $cache->set('keep', 'value');
        $cache->set('keep-a-while', 'value', 3600);
        $cache->set('stale', 'value', 3600);

        // Rewritten by hand rather than by waiting an hour.
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (unserialize(substr($contents, 10)) === 'value' && str_contains($file, basename($file))) {
                // Expire exactly one of them.
            }
        }

        $path = new ReflectionMethod(FileStore::class, 'pathFor');
        $stalePath = $path->invoke($cache, 'stale');
        file_put_contents($stalePath, str_pad((string) (time() - 10), 10, '0', STR_PAD_LEFT) . serialize('value'));

        $this->assertSame(1, $cache->prune());
        $this->assertTrue($cache->has('keep'));
        $this->assertTrue($cache->has('keep-a-while'));
        $this->assertFalse($cache->has('stale'));
    }

    #[Test]
    public function a_half_written_entry_reads_as_a_miss(string ...$ignored): void
    {
        $cache = new FileStore($this->directory);
        $cache->set('key', 'value');

        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            file_put_contents($file, '0000000000not-serialised-at-all');
        }

        // A crash mid-write must not throw on the next read.
        $this->assertSame('fallback', $cache->get('key', 'fallback'));
    }

    #[Test]
    public function a_directory_that_cannot_be_created_reports_failure(): void
    {
        $readonly = $this->directory . '/locked';
        mkdir($readonly, 0o755, true);
        chmod($readonly, 0o500);

        try {
            if (is_writable($readonly)) {
                $this->markTestSkipped('Running as a user that can write anywhere.');
            }

            $cache = new FileStore($readonly . '/inside');

            // Reporting success for a write that never happened is worse than
            // not caching at all: the caller stops checking.
            $this->assertFalse($cache->set('key', 'value'));
            $this->assertFalse($cache->has('key'));
        } finally {
            chmod($readonly, 0o755);
            rmdir($readonly);
        }
    }

    #[Test]
    public function a_file_that_cannot_be_written_reports_failure(): void
    {
        // The directory exists, so creating it succeeds; it is the write
        // itself that fails.
        $readonly = $this->directory . '/existing-but-locked';
        mkdir($readonly, 0o755, true);
        chmod($readonly, 0o500);

        try {
            if (is_writable($readonly)) {
                $this->markTestSkipped('Running as a user that can write anywhere.');
            }

            $this->assertFalse((new FileStore($readonly))->set('key', 'value'));
        } finally {
            chmod($readonly, 0o755);
            rmdir($readonly);
        }
    }

    #[Test]
    public function an_object_survives_the_round_trip(): void
    {
        $cache = new FileStore($this->directory);
        $cache->set('object', new CacheablePreferences('dark', 14));

        $read = $cache->get('object');

        $this->assertInstanceOf(CacheablePreferences::class, $read);
        $this->assertSame('dark', $read->theme);
        $this->assertSame(14, $read->fontSize);
    }

    #[Test]
    public function an_unreadable_entry_is_skipped_by_prune(): void
    {
        $cache = new FileStore($this->directory);
        $cache->set('key', 'value', 60);

        $file = (glob($this->directory . '/*.cache') ?: [])[0];
        chmod($file, 0o000);

        try {
            if (is_readable($file)) {
                $this->markTestSkipped('Running as a user that can read anything.');
            }

            // Cannot be inspected, so it is left alone rather than throwing.
            $this->assertSame(0, $cache->prune());
        } finally {
            chmod($file, 0o644);
        }
    }

    #[Test]
    public function set_multiple_reports_failure_if_any_write_fails(): void
    {
        $readonly = $this->directory . '/locked2';
        mkdir($readonly, 0o755, true);
        chmod($readonly, 0o500);

        try {
            if (is_writable($readonly)) {
                $this->markTestSkipped('Running as a user that can write anywhere.');
            }

            $this->assertFalse((new FileStore($readonly))->setMultiple(['a' => 1, 'b' => 2]));
        } finally {
            chmod($readonly, 0o755);
            rmdir($readonly);
        }
    }

    #[Test]
    public function pruning_uses_the_same_clock_as_everything_else(): void
    {
        $now = 2_000_000;
        $cache = new FileStore($this->directory, function () use (&$now): int {
            return $now;
        });

        $cache->set('brief', 'value', 60);
        $cache->set('forever', 'value');

        $this->assertSame(0, $cache->prune(), 'nothing has expired yet');

        $now += 60;

        $this->assertSame(1, $cache->prune());
        $this->assertTrue($cache->has('forever'));
    }

    #[Test]
    public function the_directory_is_created_on_first_write(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);

        (new FileStore($this->directory))->set('key', 'value');

        $this->assertDirectoryExists($this->directory);
    }
    // --- payload authentication -------------------------------------------

    private function authenticated(): FileStore
    {
        return new FileStore($this->directory, secret: 'an-application-key');
    }

    /** The file backing the only entry in the cache directory. */
    private function onlyEntry(): string
    {
        $files = glob($this->directory . '/*.cache') ?: [];

        $this->assertCount(1, $files);

        return $files[0];
    }

    #[Test]
    public function an_authenticated_entry_round_trips(): void
    {
        $cache = $this->authenticated();
        $cache->set('prefs', new CacheablePreferences('dark', 14));

        $this->assertEquals(new CacheablePreferences('dark', 14), $cache->get('prefs'));
    }

    #[Test]
    public function a_forged_payload_is_refused(): void
    {
        // Reading a cache entry means unserialising it. An attacker who can
        // write a file anywhere on the box -- an upload bug, a zip extraction,
        // a log written somewhere unfortunate -- would otherwise get to choose
        // the bytes handed to unserialize(), which is code execution wherever
        // the installed classes contain a usable gadget.
        $cache = $this->authenticated();
        $cache->set('prefs', 'genuine');

        file_put_contents(
            $this->onlyEntry(),
            '0000000000' . str_repeat('a', 64) . serialize(new CacheablePreferences('pwned', 0)),
        );

        $this->assertSame('MISS', $cache->get('prefs', 'MISS'));
    }

    #[Test]
    public function a_payload_too_short_to_hold_a_mac_is_refused(): void
    {
        $cache = $this->authenticated();
        $cache->set('prefs', 'genuine');

        file_put_contents($this->onlyEntry(), '0000000000' . serialize('unauthenticated'));

        $this->assertSame('MISS', $cache->get('prefs', 'MISS'));
    }

    #[Test]
    public function the_expiry_cannot_be_extended_without_the_secret(): void
    {
        // The MAC covers the expiry as well as the value, so a stolen entry
        // cannot be given a longer life than it was written with.
        $cache = $this->authenticated();
        $cache->set('prefs', 'genuine', 60);

        $entry = $this->onlyEntry();
        file_put_contents($entry, '9999999999' . substr((string) file_get_contents($entry), 10));

        $this->assertSame('MISS', $cache->get('prefs', 'MISS'));
    }

    #[Test]
    public function an_entry_written_under_a_different_secret_is_refused(): void
    {
        // Rotating the application key invalidates the cache rather than
        // trusting entries nobody can vouch for any more.
        (new FileStore($this->directory, secret: 'old-key'))->set('prefs', 'genuine');

        $this->assertSame(
            'MISS',
            (new FileStore($this->directory, secret: 'new-key'))->get('prefs', 'MISS'),
        );
    }

    #[Test]
    public function a_false_value_survives_authentication(): void
    {
        // unserialize() returns false on failure *and* for a stored false, so
        // the two have to stay distinguishable once a MAC is in front of them.
        $cache = $this->authenticated();
        $cache->set('flag', false);

        $this->assertFalse($cache->get('flag', 'MISS'));
    }

    #[Test]
    public function an_empty_secret_means_no_secret(): void
    {
        // A blank APP_KEY is a key nobody set. It must not become a MAC key
        // that every installation shipping the same stock .env would share.
        $cache = new FileStore($this->directory, secret: '');
        $cache->set('prefs', 'genuine');

        $this->assertSame('genuine', $cache->get('prefs'));
        $this->assertStringNotContainsString(
            'a',
            substr((string) file_get_contents($this->onlyEntry()), 10, 1),
        );
    }

    #[Test]
    public function without_a_secret_entries_are_plain(): void
    {
        $cache = new FileStore($this->directory);
        $cache->set('prefs', 'genuine');

        $this->assertSame('genuine', $cache->get('prefs'));
        $this->assertSame(
            '0000000000' . serialize('genuine'),
            file_get_contents($this->onlyEntry()),
        );
    }

    #[Test]
    public function the_authenticated_format_is_fixed(): void
    {
        // A known answer, not a round trip. Round trips pass however the key
        // is derived, so they would not notice a change to the derivation --
        // and any such change silently invalidates every cache entry on every
        // deployed installation, which looks like a mysterious cold cache
        // rather than like a bug. Pinning the bytes makes the on-disk format
        // something you have to break on purpose.
        $cache = $this->authenticated();
        $cache->set('prefs', 'genuine');

        $this->assertSame(
            '0000000000'
            . 'c8143d1fbab831ddbf76cf7f5e3b32319956755cf1433ee87bf58c78aad9afa4'
            . 's:7:"genuine";',
            file_get_contents($this->onlyEntry()),
        );
    }

    #[Test]
    public function an_authenticated_entry_still_expires(): void
    {
        $now = 1_000;
        $cache = new FileStore($this->directory, function () use (&$now): int {
            return $now;
        }, 'an-application-key');
        $cache->set('prefs', 'genuine', 10);

        $this->assertSame('genuine', $cache->get('prefs'));

        $now = 1_011;
        $this->assertSame('MISS', $cache->get('prefs', 'MISS'));
    }
}

final class CacheablePreferences
{
    public function __construct(
        public readonly string $theme,
        public readonly int $fontSize,
    ) {}
}
