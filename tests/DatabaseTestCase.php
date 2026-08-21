<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Phpvin\Database\Connection;
use Phpvin\Database\Model;
use RuntimeException;

/**
 * Base class for tests that touch a real database.
 *
 * The same tests run against SQLite, MySQL and Postgres; set `DB_DRIVER` to
 * pick one. That matters more than it sounds: PDO returns every column as a
 * string on MySQL, Postgres rejects the backticks MySQL requires and reports
 * booleans as 't'/'f', and none of that shows up if you only ever run SQLite.
 *
 *     DB_DRIVER=pgsql vendor/bin/phpunit
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $db;

    /** @var list<string> Tables to drop when the test finishes. */
    private array $created = [];

    public static function driver(): string
    {
        $driver = getenv('DB_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : 'sqlite';
    }

    public static function newConnection(): Connection
    {
        return match (self::driver()) {
            'sqlite' => Connection::sqliteInMemory(),
            'mysql' => Connection::fromConfig([
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '33061',
                'database' => getenv('DB_DATABASE') ?: 'phpvin_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: 'secret',
            ]),
            'pgsql' => Connection::fromConfig([
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '54321',
                'database' => getenv('DB_DATABASE') ?: 'phpvin_test',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: 'secret',
            ]),
            default => throw new RuntimeException('Unknown DB_DRIVER [' . self::driver() . '].'),
        };
    }

    protected function setUp(): void
    {
        $this->db = self::newConnection();
        Model::useConnection($this->db);
    }

    protected function tearDown(): void
    {
        // Reverse order, so a child table goes before the parent it references.
        foreach (array_reverse($this->created) as $table) {
            $this->db->statement('DROP TABLE IF EXISTS ' . $this->q($table));
        }

        $this->created = [];
        Model::useConnection(null);
    }

    /** Quote an identifier the way this driver expects. */
    protected function q(string $identifier): string
    {
        return $this->db->grammar()->quote($identifier);
    }

    /**
     * Rewrite a backtick-quoted SQL expectation into this driver's quoting.
     *
     * Lets one assertion cover every dialect: write the expectation the way
     * MySQL would render it and this adjusts it for whoever is running.
     */
    protected function sql(string $backticked): string
    {
        return (string) preg_replace_callback(
            '/`([A-Za-z_][A-Za-z0-9_]*)`/',
            fn (array $m): string => $this->q($m[1]),
            $backticked,
        );
    }

    /**
     * Create a table from a portable column vocabulary.
     *
     *     $this->createTable('posts', [
     *         'id' => 'id', 'title' => 'string', 'views' => 'int', 'live' => 'bool',
     *     ] + self::TIMESTAMPS);
     *
     * @param array<string, string> $columns name => portable type
     */
    protected function createTable(string $name, array $columns): void
    {
        $definitions = [];

        foreach ($columns as $column => $type) {
            $definitions[] = $this->q($column) . ' ' . $this->sqlType($type);
        }

        // Dropped first so a rerun after a crashed test starts clean.
        $this->db->statement('DROP TABLE IF EXISTS ' . $this->q($name));
        $this->db->statement(sprintf('CREATE TABLE %s (%s)', $this->q($name), implode(', ', $definitions)));

        $this->created[] = $name;
    }

    protected const TIMESTAMPS = ['created_at' => 'string', 'updated_at' => 'string'];

    private function sqlType(string $type): string
    {
        $driver = $this->db->driver();

        return match ($type) {
            'id' => match ($driver) {
                'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => 'SERIAL PRIMARY KEY',
                default => 'INTEGER PRIMARY KEY',
            },
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'int' => 'INTEGER',
            // Stored as a small integer everywhere, so the portable path is
            // tested; native_bool covers the Postgres-specific case.
            'bool' => $driver === 'mysql' ? 'TINYINT' : 'SMALLINT',
            'native_bool' => $driver === 'pgsql' ? 'BOOLEAN' : ($driver === 'mysql' ? 'TINYINT(1)' : 'INTEGER'),
            'float' => $driver === 'sqlite' ? 'REAL' : 'DOUBLE PRECISION',
            default => throw new InvalidArgumentException("No portable mapping for column type [$type]."),
        };
    }
}
