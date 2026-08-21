<?php

declare(strict_types=1);

namespace Phpvin\Tests;

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
}
