<?php

declare(strict_types=1);

namespace Phpvin\Database\Grammar;

use InvalidArgumentException;

/**
 * The dialect differences between the databases phpvin supports.
 *
 * There are not many, but the ones that exist are not negotiable: Postgres
 * rejects the backticks MySQL requires, and MySQL rejects a bare OFFSET that
 * Postgres is happy with. Everything driver-specific about SQL generation
 * lives here, so QueryBuilder can be written once.
 */
abstract class Grammar
{
    public static function for(string $driver): self
    {
        return match ($driver) {
            'sqlite' => new SqliteGrammar(),
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            default => throw new InvalidArgumentException(
                "No SQL grammar for driver [$driver]. Supported: sqlite, mysql, pgsql.",
            ),
        };
    }

    /** The character an identifier is wrapped in. */
    abstract protected function delimiter(): string;

    /**
     * Quote a table or column name, rejecting anything that is not a plain
     * name or a `table.column` pair.
     *
     * This is the only place an identifier reaches SQL, and it never accepts
     * anything a user could have supplied.
     */
    public function quote(string $name): string
    {
        $delimiter = $this->delimiter();
        $parts = explode('.', $name);

        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) !== 1) {
                throw new InvalidArgumentException(
                    "[$name] is not a valid column or table name. Identifiers cannot be built from user input.",
                );
            }
        }

        return $delimiter . implode($delimiter . '.' . $delimiter, $parts) . $delimiter;
    }

    /**
     * Compile the row-limiting clause.
     *
     * MySQL and SQLite refuse OFFSET without LIMIT; Postgres allows it.
     */
    /**
     * Compile one ORDER BY term with an explicit place for nulls.
     *
     * Engines disagree about where a null sorts, and they disagree silently.
     * SQLite and MySQL treat null as the smallest value, so a DESC sort puts
     * nulls last; Postgres treats it as the largest, so the same sort puts
     * them first. Add a LIMIT and the two return different rows for the same
     * query, which is exactly the sort of thing that is found in production
     * rather than in a test.
     *
     * Postgres and SQLite both understand NULLS FIRST/LAST. MySQL does not,
     * and is given the `IS NULL` form that means the same thing.
     *
     * @param string|null $nulls 'first', 'last', or null for the engine default
     */
    public function compileOrder(string $quotedColumn, string $direction, ?string $nulls): string
    {
        $term = $quotedColumn . ' ' . $direction;

        if ($nulls === null) {
            return $term;
        }

        return $term . ' NULLS ' . strtoupper($nulls);
    }

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        if ($offset !== null) {
            if ($limit === null) {
                $sql .= ' LIMIT ' . $this->limitlessLimit();
            }

            $sql .= ' OFFSET ' . $offset;
        }

        return $sql;
    }

    /**
     * Stand-in used when an OFFSET has no LIMIT beside it. Every supported
     * driver accepts this as a bigint.
     */
    protected function limitlessLimit(): string
    {
        return (string) PHP_INT_MAX;
    }

    /**
     * Whether DDL inside a transaction can be rolled back.
     *
     * SQLite and Postgres can. MySQL cannot: it commits the open transaction
     * as soon as it sees CREATE/ALTER/DROP, so a migration that fails halfway
     * leaves the earlier statements applied.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Whether INSERT can hand the new key straight back.
     *
     * Where it cannot, the driver's lastInsertId() is used instead.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * The clause that returns the new key, for grammars that support it.
     */
    public function compileReturning(string $column): string
    {
        return ' RETURNING ' . $this->quote($column);
    }

    /**
     * Normalise a value the driver handed back for a boolean column.
     *
     * Postgres returns 't'/'f' rather than 1/0, which every naive cast in PHP
     * gets wrong: `(bool) 'f'` is true.
     */
    public function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                't', 'true', 'y', 'yes', 'on', '1' => true,
                'f', 'false', 'n', 'no', 'off', '0', '' => false,
                default => (bool) $value,
            };
        }

        return (bool) $value;
    }

    /**
     * The value to store for a boolean.
     */
    public function fromBool(bool $value): int|bool
    {
        return $value ? 1 : 0;
    }
}
