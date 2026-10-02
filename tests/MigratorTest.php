<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Phpvin\Database\Migrator;
use RuntimeException;
use Throwable;

final class MigratorTest extends DatabaseTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/phpvin-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->path)) {
            rmdir($this->path);
        }

        foreach (['posts', 'first', 'second', 'half_done', 'migrations'] as $table) {
            $this->db->statement('DROP TABLE IF EXISTS ' . $this->q($table));
        }

        parent::tearDown();
    }

    private function migration(string $name, string $body): void
    {
        file_put_contents(
            $this->path . "/$name.php",
            "<?php use Phpvin\\Database\\Connection; return function (Connection \$db): void { $body };",
        );
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, $this->path);
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->db->select('SELECT * FROM ' . $this->q($table) . ' WHERE 1 = 0');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    #[Test]
    public function it_runs_a_pending_migration(): void
    {
        $this->migration('001_create_posts', '$db->statement("CREATE TABLE posts (id INTEGER PRIMARY KEY)");');

        $applied = $this->migrator()->run();

        $this->assertSame(['001_create_posts'], $applied);
        $this->assertTrue($this->tableExists('posts'));
    }

    #[Test]
    public function it_runs_migrations_in_filename_order(): void
    {
        $this->migration('002_second', '$db->statement("CREATE TABLE second (id INTEGER PRIMARY KEY)");');
        $this->migration('001_first', '$db->statement("CREATE TABLE first (id INTEGER PRIMARY KEY)");');

        $this->assertSame(['001_first', '002_second'], $this->migrator()->run());
    }

    #[Test]
    public function running_twice_applies_nothing_the_second_time(): void
    {
        $this->migration('001_create_posts', '$db->statement("CREATE TABLE posts (id INTEGER PRIMARY KEY)");');

        $this->migrator()->run();

        // Re-running must be a no-op, not a "table already exists" error.
        $this->assertSame([], $this->migrator()->run());
    }

    #[Test]
    public function only_the_new_migration_runs_on_a_second_pass(): void
    {
        $this->migration('001_first', '$db->statement("CREATE TABLE first (id INTEGER PRIMARY KEY)");');
        $this->migrator()->run();

        $this->migration('002_second', '$db->statement("CREATE TABLE second (id INTEGER PRIMARY KEY)");');

        $this->assertSame(['002_second'], $this->migrator()->run());
        $this->assertTrue($this->tableExists('first'));
        $this->assertTrue($this->tableExists('second'));
    }

    #[Test]
    public function pending_reports_what_has_not_run(): void
    {
        $this->migration('001_first', '$db->statement("CREATE TABLE first (id INTEGER PRIMARY KEY)");');
        $this->migration('002_second', '$db->statement("CREATE TABLE second (id INTEGER PRIMARY KEY)");');

        $this->assertSame(['001_first', '002_second'], array_keys($this->migrator()->pending()));

        $this->migrator()->run();

        $this->assertSame([], $this->migrator()->pending());
    }

    #[Test]
    public function a_failing_migration_is_rolled_back_and_stays_pending(): void
    {
        $this->migration('001_broken', <<<'PHP'
            $db->statement("CREATE TABLE half_done (id INTEGER PRIMARY KEY)");
            $db->statement("THIS IS NOT SQL");
        PHP);

        try {
            $this->migrator()->run();
            $this->fail('Expected the migration to throw.');
        } catch (Throwable) {
            // expected
        }

        // This half must hold everywhere: an unrecorded migration is retried,
        // a recorded one is silently skipped and the schema stays broken.
        $this->assertArrayHasKey('001_broken', $this->migrator()->pending());

        if ($this->db->grammar()->supportsTransactionalDdl()) {
            $this->assertFalse($this->tableExists('half_done'), 'the partial schema was rolled back');
        } else {
            // MySQL commits implicitly on DDL, so the earlier statement stands.
            // The framework cannot undo that; it can only be honest about it.
            $this->assertTrue($this->tableExists('half_done'), 'MySQL cannot roll back DDL');
        }
    }

    #[Test]
    public function a_migration_that_does_not_return_a_closure_is_rejected(): void
    {
        file_put_contents($this->path . '/001_wrong.php', '<?php return "not a closure";');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a closure');

        $this->migrator()->run();
    }

    #[Test]
    public function a_missing_directory_is_reported_clearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new Migrator($this->db, $this->path . '/nope'))->run();
    }

    #[Test]
    public function an_empty_directory_is_fine(): void
    {
        $this->assertSame([], $this->migrator()->run());
    }

    #[Test]
    public function the_ledger_records_when_each_migration_ran(): void
    {
        $this->migration('001_first', '$db->statement("CREATE TABLE first (id INTEGER PRIMARY KEY)");');
        $this->migrator()->run();

        $row = $this->db->selectOne('SELECT name, applied_at FROM migrations');

        $this->assertSame('001_first', $row['name']);
        $this->assertNotEmpty($row['applied_at']);
    }

    // --- concurrent deploys -----------------------------------------------

    private function hasAdvisoryLock(): bool
    {
        return $this->db->grammar()->migrationLock() !== null;
    }

    #[Test]
    public function two_deploys_at_once_apply_each_migration_exactly_once(): void
    {
        // Rolling deploys, replicas, a worker and a web process booting
        // together: several run `migrate` at the same moment, all ask what is
        // pending, all get the same answer, and all act on it. Before the lock,
        // three of four simultaneous runs died on `CREATE TABLE ... already
        // exists` -- three instances that never came up.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped($this->db->driver() . ' has no advisory lock; concurrent runs are not supported.');
        }

        $this->db->statement('DROP TABLE IF EXISTS concurrent_widgets');

        file_put_contents($this->path . '/001_widgets.php', <<<'PHP'
            <?php
            return function (Phpvin\Database\Connection $db): void {
                $db->statement('CREATE TABLE concurrent_widgets (id INT NOT NULL)');
                $db->statement('INSERT INTO concurrent_widgets (id) VALUES (1)');
            };
            PHP);

        $results = $this->spawnMigrations(4);

        $this->assertSame(
            [],
            array_filter($results, static fn (string $r): bool => ! str_starts_with($r, 'ok')),
            'a concurrent deploy failed',
        );

        $this->assertCount(1, $this->db->select('SELECT * FROM concurrent_widgets'));
        $this->assertCount(1, $this->db->select("SELECT name FROM migrations WHERE name = '001_widgets'"));

        $this->db->statement('DROP TABLE IF EXISTS concurrent_widgets');
    }

    #[Test]
    public function the_lock_is_released_after_a_successful_run(): void
    {
        // A lock still held after the run is a deploy that works once and then
        // blocks every later one until something restarts.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        file_put_contents($this->path . '/001_ok.php', '<?php return function ($db) {};');

        $this->migrator()->run();

        file_put_contents($this->path . '/002_ok.php', '<?php return function ($db) {};');

        $this->assertSame(['002_ok'], $this->migrator()->run(), 'the second run could not take the lock');
    }

    #[Test]
    public function the_lock_is_released_when_a_migration_throws(): void
    {
        // The failure path is the one that matters: a migration that blows up
        // and leaves the lock held turns one bad deploy into every later
        // deploy hanging.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        file_put_contents($this->path . '/001_bad.php', '<?php return function ($db) { throw new RuntimeException("nope"); };');

        try {
            $this->migrator()->run();
            $this->fail('Expected the migration to throw.');
        } catch (Throwable) {
            // expected
        }

        unlink($this->path . '/001_bad.php');
        file_put_contents($this->path . '/002_ok.php', '<?php return function ($db) {};');

        $this->assertSame(['002_ok'], $this->migrator()->run(), 'the lock outlived a failed migration');
    }

    #[Test]
    public function a_run_gives_up_rather_than_waiting_forever(): void
    {
        // Held by someone else and never released: the deploy has to fail with
        // something a human can read, not hang until a scheduler kills it.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        $holder = DatabaseTestCase::newConnection();
        $lock = $holder->grammar()->migrationLock();
        $this->assertNotNull($lock);
        $holder->select($lock->acquire);

        file_put_contents($this->path . '/001_ok.php', '<?php return function ($db) {};');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('has been migrating');

            (new Migrator($this->db, $this->path, lockTimeoutSeconds: 1))->run();
        } finally {
            $holder->select($lock->release);
        }
    }

    #[Test]
    public function the_lock_is_given_back_to_other_connections_too(): void
    {
        // MySQL's GET_LOCK is re-entrant within a session, so a second run on
        // the same connection succeeds whether or not the release ever
        // happened. Only a different connection can show the lock was actually
        // handed back -- which is the case that matters, because the next
        // deploy is always a different connection.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        file_put_contents($this->path . '/001_ok.php', '<?php return function ($db) {};');

        $this->migrator()->run();

        $other = DatabaseTestCase::newConnection();
        $lock = $other->grammar()->migrationLock();

        $this->assertNotNull($lock);

        $row = $other->selectOne($lock->acquire);

        $this->assertNotNull($row);
        $this->assertTrue(
            $other->grammar()->toBool(reset($row)),
            'a fresh connection could not take the lock, so the run never released it',
        );

        $other->select($lock->release);
    }

    #[Test]
    public function a_run_waits_for_the_holder_and_then_proceeds(): void
    {
        // The other half of the timeout: a deploy that finds the lock held
        // must *wait* and then carry on, not give up on the first refusal.
        // Only this direction pins the deadline as start-plus-timeout -- a
        // deadline computed the other way lands in the past, and every wait
        // becomes an immediate failure that still looks like a timeout.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        $holder = DatabaseTestCase::newConnection();
        $lock = $holder->grammar()->migrationLock();

        $this->assertNotNull($lock);
        $holder->select($lock->acquire);

        file_put_contents($this->path . '/001_ok.php', '<?php return function ($db) {};');

        // The holder lets go while the run is waiting. Driving that from the
        // clock keeps it deterministic: there is no second thread to race.
        $calls = 0;
        $released = false;
        $clock = static function () use (&$calls, &$released, $holder, $lock): int {
            $calls++;

            if ($calls >= 2 && ! $released) {
                $released = true;
                $holder->select($lock->release);
            }

            return 1_000;
        };

        $applied = (new Migrator($this->db, $this->path, lockTimeoutSeconds: 5, clock: $clock))->run();

        $this->assertTrue($released, 'the run never waited');
        $this->assertSame(['001_ok'], $applied);
    }

    #[Test]
    public function a_run_gives_up_the_moment_the_deadline_is_reached(): void
    {
        // The clock is scripted rather than real: it reports the start, then
        // exactly the deadline. Waiting *past* the deadline instead of *at* it
        // would ask the clock a third time, and there is no third answer --
        // so an off-by-one here fails loudly instead of merely waiting one
        // round longer than intended.
        if (! $this->hasAdvisoryLock()) {
            $this->markTestSkipped('No advisory lock on this driver.');
        }

        $holder = DatabaseTestCase::newConnection();
        $lock = $holder->grammar()->migrationLock();

        $this->assertNotNull($lock);
        $holder->select($lock->acquire);

        file_put_contents($this->path . '/001_ok.php', '<?php return function ($db) {};');

        $times = [1_000, 1_005];
        $clock = static function () use (&$times): int {
            $next = array_shift($times);

            if ($next === null) {
                throw new LogicException('the deadline was overshot');
            }

            return $next;
        };

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('has been migrating');

            (new Migrator($this->db, $this->path, lockTimeoutSeconds: 5, clock: $clock))->run();
        } finally {
            $holder->select($lock->release);
        }
    }

    /**
     * @return list<string>
     */
    private function spawnMigrations(int $count): array
    {
        $worker = $this->path . '/../worker.php';
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $driver = DatabaseTestCase::driver();
        $path = $this->path;

        file_put_contents($worker, <<<WORKER
            <?php
            require '{$autoload}';
            \$db = \Phpvin\Tests\DatabaseTestCase::newConnection();
            try { (new \Phpvin\Database\Migrator(\$db, '{$path}'))->run(); echo 'ok'; }
            catch (\Throwable \$e) { echo 'ERR: ', explode("\n", \$e->getMessage())[0]; }
            WORKER);

        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $process = proc_open(
                [PHP_BINARY, $worker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['DB_DRIVER' => $driver] + $_ENV + getenv(),
            );

            if ($process === false) {
                $this->markTestSkipped('Could not spawn a worker process.');
            }

            $processes[] = [$process, $pipes];
        }

        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1]));
            stream_get_contents($pipes[2]);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }

        return $results;
    }
}
