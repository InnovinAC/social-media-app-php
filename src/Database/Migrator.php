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
 * A run holds an advisory lock for its whole duration, because the race is
 * between asking what is pending and acting on the answer. Without it, several
 * servers deploying together all get the same answer and all act on it: on
 * MySQL, three of four simultaneous runs die on `CREATE TABLE ... already
 * exists`. SQLite has no advisory lock and is left unlocked, which suits how
 * it is deployed.
 *
 * Each migration runs inside a transaction, which on SQLite and Postgres means
 * a failure halfway through leaves no trace. MySQL commits implicitly on DDL,
 * so there the earlier statements in a failed migration stay applied. Check
 * `Grammar::supportsTransactionalDdl()` if that matters to you. Either way the
 * migration is not recorded, so a retry runs it again.
 */
final class Migrator
{
    /**
     * @param int $lockTimeoutSeconds How long to wait for another deploy's
     *                                migration to finish before giving up.
     */
    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param int                   $lockTimeoutSeconds How long to wait for
     *                                                  another deploy's
     *                                                  migration to finish.
     * @param (Closure(): int)|null $clock              Where "now" comes from.
     *                                                  Injectable so a test can
     *                                                  sit on the deadline
     *                                                  rather than wait for it.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $path,
        private readonly int $lockTimeoutSeconds = 60,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Apply every migration that has not run yet.
     *
     * @return list<string> The migrations applied, in order.
     */
    public function run(): array
    {
        // Held across the whole run, not per migration: the race is between
        // asking what is pending and acting on the answer, so the lock has to
        // cover both or it covers nothing.
        return $this->withLock(function (): array {
            return $this->apply();
        });
    }

    /**
     * Run `$work` with the migration lock held, where the driver has one.
     *
     * @template T
     *
     * @param  Closure(): T $work
     * @return T
     */
    private function withLock(Closure $work): mixed
    {
        $lock = $this->connection->grammar()->migrationLock();

        if ($lock === null) {
            // SQLite has no advisory lock. Its deployments are single-writer
            // by nature, and inventing a lock table here would trade a rare
            // race for a stale row that outlives a crash and needs a human.
            return $work();
        }

        $deadline = ($this->clock)() + $this->lockTimeoutSeconds;

        while (! $this->tryLock($lock->acquire)) {
            if (($this->clock)() >= $deadline) {
                throw new RuntimeException(sprintf(
                    'Another process has been migrating for more than %d seconds. '
                    . 'If nothing else is deploying, its connection may still be open.',
                    $this->lockTimeoutSeconds,
                ));
            }

            usleep(200_000);
        }

        try {
            return $work();
        } finally {
            $this->connection->select($lock->release);
        }
    }

    private function tryLock(string $sql): bool
    {
        $row = $this->connection->selectOne($sql);

        // reset() on an empty row yields false, which toBool reads as "not
        // acquired", so an empty answer needs no case of its own. The boolean
        // goes through the grammar because Postgres reports false as the
        // string 'f', and `(bool) 'f'` is true.
        return $row !== null && $this->connection->grammar()->toBool(reset($row));
    }

    /**
     * @return list<string>
     */
    private function apply(): array
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
