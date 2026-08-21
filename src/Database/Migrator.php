<?php

declare(strict_types=1);

namespace Phpvin\Database;

use Closure;
use RuntimeException;

/**
 * Runs migration files in filename order, once each.
 *
 * A migration is a PHP file returning a closure:
 *
 *     <?php
 *     use Phpvin\Database\Connection;
 *
 *     return function (Connection $db): void {
 *         $db->statement('CREATE TABLE posts (...)');
 *     };
 *
 * Applied migrations are recorded in a `migrations` table, so running twice is
 * a no-op rather than an error.
 *
 * Each migration runs inside a transaction, which on SQLite and Postgres means
 * a failure halfway through leaves no trace. MySQL commits implicitly on DDL,
 * so there the earlier statements in a failed migration stay applied. Check
 * `Grammar::supportsTransactionalDdl()` if that matters to you. Either way the
 * migration is not recorded, so a retry runs it again.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
    ) {}

    /**
     * Apply every migration that has not run yet.
     *
     * @return list<string> The migrations applied, in order.
     */
    public function run(): array
    {
        $this->ensureLedgerExists();

        $applied = [];

        foreach ($this->pending() as $name => $file) {
            $migration = require $file;

            if (! $migration instanceof Closure) {
                throw new RuntimeException("Migration [$name] must return a closure.");
            }

            $this->connection->transaction(function (Connection $db) use ($migration, $name): void {
                $migration($db);
                $db->statement('INSERT INTO migrations (name, applied_at) VALUES (?, ?)', [
                    $name,
                    date('Y-m-d H:i:s'),
                ]);
            });

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return array<string, string> Migration name => absolute file path.
     */
    public function pending(): array
    {
        $this->ensureLedgerExists();

        $done = array_column($this->connection->select('SELECT name FROM migrations'), 'name');
        $pending = [];

        foreach ($this->files() as $name => $file) {
            if (! in_array($name, $done, true)) { // mutation:ignore strict flag is equivalent for an array of string literals
                $pending[$name] = $file;
            }
        }

        return $pending;
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        if (! is_dir($this->path)) {
            throw new RuntimeException("Migration directory [{$this->path}] does not exist.");
        }

        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files);

        $indexed = [];

        foreach ($files as $file) {
            $indexed[basename($file, '.php')] = $file;
        }

        return $indexed;
    }

    private function ensureLedgerExists(): void
    {
        $this->connection->statement(
            'CREATE TABLE IF NOT EXISTS migrations (
                name VARCHAR(255) NOT NULL,
                applied_at VARCHAR(32) NOT NULL
            )',
        );
    }
}
