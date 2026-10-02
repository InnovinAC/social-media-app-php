<?php

declare(strict_types=1);

namespace Phpvin\Database;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Phpvin\Database\Grammar\Grammar;
use Throwable;

/**
 * A PDO wrapper. Every query goes through a prepared statement; there is no
 * method on this class that interpolates a value into SQL.
 */
final class Connection
{
    private int $transactionDepth = 0;

    private int $queryCount = 0;

    /** @var list<array{sql: string, bindings: array<array-key, mixed>}>|null */
    private ?array $queryLog = null;

    private ?Grammar $grammar = null;

    /**
     * @param PDO|Closure(): PDO $pdo A closure defers connecting until the
     *                                first query, so a page that never touches
     *                                the database never opens a socket.
     */
    public function __construct(private PDO|Closure $pdo)
    {
        if ($this->pdo instanceof PDO) {
            $this->configure($this->pdo);
        }
    }

    /**
     * @param array{driver?: string, host?: string, port?: string|int, database?: string,
     *              username?: string, password?: string, charset?: string} $config
     */
    public static function fromConfig(array $config): self
    {
        $driver = $config['driver'] ?? 'mysql';
        $database = $config['database'] ?? '';

        $dsn = match ($driver) {
            'sqlite' => "sqlite:$database",
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '3306',
                $database,
                $config['charset'] ?? 'utf8mb4',
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? '5432',
                $database,
            ),
            default => throw new QueryException("Unsupported database driver [$driver]."),
        };

        return new self(static function () use ($dsn, $driver, $config): PDO {
            try {
                return new PDO($dsn, $config['username'] ?? null, $config['password'] ?? null);
            } catch (PDOException $e) {
                throw new QueryException(
                    "Could not connect to the [$driver] database: {$e->getMessage()}",
                    previous: $e,
                );
            }
        });
    }

    /**
     * An in-memory SQLite connection. Used by the test suite; also the fastest
     * way to try the framework without installing anything.
     */
    public static function sqliteInMemory(): self
    {
        return new self(new PDO('sqlite::memory:'));
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof Closure) {
            $this->pdo = ($this->pdo)();
            $this->configure($this->pdo);
        }

        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * The SQL dialect for this connection.
     *
     * Postgres rejects the backticks MySQL requires, so nothing may assume a
     * quoting style without asking.
     */
    public function grammar(): Grammar
    {
        return $this->grammar ??= Grammar::for($this->driver());
    }

    /**
     * How many statements this connection has run.
     *
     * Cheap enough to leave on, and it turns "did that eager load actually
     * collapse the queries?" into something a test can assert.
     */
    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Start recording every statement, for debugging a slow page.
     */
    public function enableQueryLog(): void
    {
        $this->queryLog ??= [];
    }

    /**
     * @return list<array{sql: string, bindings: array<array-key, mixed>}>
     */
    public function queryLog(): array
    {
        return $this->queryLog ?? [];
    }

    private function configure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * @param  list<mixed>|array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * @param  list<mixed>|array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param  list<mixed>|array<string, mixed> $bindings
     * @return int Number of affected rows.
     */
    public function statement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->run($sql, $bindings);

        return $this->pdo()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, rolling back on any throwable.
     * Nested calls use savepoints where the driver supports them.
     *
     * @template T
     * @param  Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    /**
     * How many nested transactions are currently open.
     */
    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

    public function beginTransaction(): void
    {
        // The driver is the authority, not our counter. MySQL commits an open
        // transaction the moment it sees DDL, so by the time we get here the
        // transaction we think we are inside may already be gone.
        if ($this->transactionDepth === 0 || ! $this->pdo()->inTransaction()) {
            $this->transactionDepth = 0;
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT phpvin_sp' . $this->transactionDepth);
        }

        $this->transactionDepth++;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;

        // Committed underneath us already, so resync rather than emitting
        // `RELEASE SAVEPOINT phpvin_sp-1`, which is what used to happen.
        if (! $this->pdo()->inTransaction()) {
            $this->transactionDepth = 0;

            return;
        }

        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT phpvin_sp' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }

        $this->transactionDepth--;

        if (! $this->pdo()->inTransaction()) {
            $this->transactionDepth = 0;

            return;
        }

        if ($this->transactionDepth === 0) {
            $this->pdo()->rollBack();
        } else {
            $this->pdo()->exec('ROLLBACK TO SAVEPOINT phpvin_sp' . $this->transactionDepth);
        }
    }

    /**
     * PDO binds a PHP bool as '1' or, fatally, '', and an empty string is not
     * a valid boolean, smallint or anything else. Postgres rejects it outright
     * and MySQL quietly stores zero. Booleans become 0/1 before they reach the
     * driver, which every supported database accepts for both integer and
     * boolean columns.
     *
     * @param  list<mixed>|array<string, mixed> $bindings
     * @return list<mixed>|array<string, mixed>
     */
    private function normaliseBindings(array $bindings): array
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 1 : 0;
            }
        }

        return $bindings;
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $this->queryCount++;

        if ($this->queryLog !== null) {
            $this->queryLog[] = ['sql' => $sql, 'bindings' => $bindings];
        }

        try {
            $statement = $this->pdo()->prepare($sql);

            // Named bindings must keep their keys; positional ones are already
            // a list by the time they reach here.
            $statement->execute($this->normaliseBindings($bindings));

            return $statement;
        } catch (PDOException $e) {
            throw new QueryException(
                sprintf('%s (SQL: %s)', $e->getMessage(), $sql),
                previous: $e,
            );
        }
    }
}
