<?php

declare(strict_types=1);

namespace Phpvin\Database;

use Closure;
use InvalidArgumentException;

/**
 * Builds SELECT/INSERT/UPDATE/DELETE statements.
 *
 * Two rules hold everywhere in this class:
 *   - values are never written into SQL, only bound
 *   - identifiers are validated against a strict pattern before quoting
 *
 * Comparison operators must be passed explicitly. `where('age', '>', 18)` and
 * `where('name', '=', 'ada')` read the same way, and there is no two-argument
 * shorthand whose meaning you have to remember.
 *
 * On AND/OR precedence: conditions are joined left to right, exactly as SQL
 * evaluates them, so `where(a)->where(b)->orWhere(c)` means `(a AND b) OR c`.
 * When you want the other grouping (and with an ownership filter you almost
 * always do), use whereGroup():
 *
 *     $query->where('author_id', '=', $me)
 *           ->whereGroup(fn (QueryBuilder $q) => $q
 *               ->where('status', '=', 'draft')
 *               ->orWhere('status', '=', 'published'));
 *
 *     // author_id = ? AND (status = ? OR status = ?)
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like', 'not like'];

    private const JOIN_TYPES = ['inner', 'left', 'right'];

    /** @var list<string> */
    private array $columns = ['*'];

    private bool $distinct = false;

    /** @var list<array{boolean: string, sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<array{boolean: string, sql: string, bindings: list<mixed>}> */
    private array $havings = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $orders = [];

    /** @var list<string> Relations to eager load after hydrating. */
    private array $with = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param class-string<Model>|null $modelClass When set, rows are hydrated
     *                                            into models instead of arrays.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly ?string $modelClass = null,
    ) {}

    // --- shape ------------------------------------------------------------

    /**
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    // --- conditions -------------------------------------------------------

    public function where(string $column, string $operator, mixed $value): self
    {
        return $this->addComparison($this->wheres, 'and', $column, $operator, $value);
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->addComparison($this->wheres, 'or', $column, $operator, $value);
    }

    /**
     * Wrap a set of conditions in parentheses.
     *
     * @param Closure(self): void $build
     */
    public function whereGroup(Closure $build, string $boolean = 'and'): self
    {
        $group = new self($this->connection, $this->table, $this->modelClass);
        $build($group);

        if ($group->wheres === []) {
            return $this;
        }

        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => '(' . self::renderConditions($group->wheres) . ')',
            'bindings' => self::collectBindings($group->wheres),
        ];

        return $this;
    }

    /**
     * @param Closure(self): void $build
     */
    public function orWhereGroup(Closure $build): self
    {
        return $this->whereGroup($build, 'or');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'and'): self
    {
        if ($values === []) {
            // An empty IN () is a syntax error in most engines and always
            // matches nothing, so say that directly.
            $this->wheres[] = ['boolean' => $boolean, 'sql' => '1 = 0', 'bindings' => []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . " IN ($placeholders)",
            'bindings' => array_values($values),
        ];

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        if ($values === []) {
            $this->wheres[] = ['boolean' => $boolean, 'sql' => '1 = 1', 'bindings' => []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . " NOT IN ($placeholders)",
            'bindings' => array_values($values),
        ];

        return $this;
    }

    public function whereNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . ' IS NULL',
            'bindings' => [],
        ];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . ' IS NOT NULL',
            'bindings' => [],
        ];

        return $this;
    }

    public function whereBetween(string $column, mixed $low, mixed $high, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . ' BETWEEN ? AND ?',
            'bindings' => [$low, $high],
        ];

        return $this;
    }

    // --- joins ------------------------------------------------------------

    /**
     * Join on a column pair. Both sides are identifiers, never values, so
     * there is nothing to bind and nothing to escape.
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): self
    {
        $type = strtolower($type);

        if (! in_array($type, self::JOIN_TYPES, true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException(sprintf(
                'Join type must be one of: %s. Got [%s].',
                implode(', ', self::JOIN_TYPES),
                $type,
            ));
        }

        if (! in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException("Unsupported join operator [$operator].");
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s %s %s',
            strtoupper($type),
            $this->identifier($table),
            $this->identifier($first),
            $operator,
            $this->identifier($second),
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    // --- grouping ---------------------------------------------------------

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->identifier($column);
        }

        return $this;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        return $this->addComparison($this->havings, 'and', $column, $operator, $value);
    }

    public function orHaving(string $column, string $operator, mixed $value): self
    {
        return $this->addComparison($this->havings, 'or', $column, $operator, $value);
    }

    // --- ordering and slicing ---------------------------------------------

    /**
     * Order by a column.
     *
     * `nulls` decides where nulls go, and on a nullable column you want to say.
     * Engines disagree about it and disagree silently: SQLite and MySQL treat
     * null as the smallest value, Postgres as the largest, so `orderBy('score',
     * 'desc')` puts nulls at opposite ends depending on the driver. Add a
     * `limit` and the same query returns different rows. Saying `nulls: 'last'`
     * pins it, and the grammar compiles it for whichever engine you are on.
     *
     * @param string|null $nulls 'first', 'last', or null for the engine default
     */
    public function orderBy(string $column, string $direction = 'asc', ?string $nulls = null): self
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException("Order direction must be asc or desc, got [$direction].");
        }

        if ($nulls !== null && ! in_array($nulls = strtolower($nulls), ['first', 'last'], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException("Null placement must be first or last, got [$nulls].");
        }

        $this->orders[] = $this->connection->grammar()->compileOrder(
            $this->identifier($column),
            strtoupper($direction),
            $nulls,
        );

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Limit cannot be negative.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset cannot be negative.');
        }

        $this->offset = $offset;

        return $this;
    }

    // --- reading ----------------------------------------------------------

    /**
     * Eager load relations, one extra query each rather than one per row.
     *
     *     Post::query()->with('author', 'comments')->get();
     */
    public function with(string ...$relations): self
    {
        $this->with = [...$this->with, ...$relations];

        return $this;
    }

    /**
     * @return list<Model|array<string, mixed>>
     */
    public function get(): array
    {
        $rows = $this->connection->select($this->toSql(), $this->bindings());

        if ($this->modelClass === null) {
            return $rows;
        }

        $models = array_map($this->modelClass::hydrate(...), $rows);

        // eagerLoad() returns early on an empty list, so there is nothing to
        // check here beyond whether anything was requested.
        if ($this->with !== []) {
            $this->modelClass::eagerLoad($models, $this->with);
        }

        return $models;
    }

    /**
     * @return Model|array<string, mixed>|null
     */
    public function first(): Model|array|null
    {
        // Clone so first() does not silently cap a builder the caller reuses.
        $query = clone $this;

        return $query->limit(1)->get()[0] ?? null;
    }

    /**
     * One page of results, plus the counts needed to render pagination.
     *
     * @return Paginator<Model|array<string, mixed>>
     */
    public function paginate(int $perPage, int $page = 1): Paginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        $total = (clone $this)->count();

        $items = (clone $this)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return new Paginator($items, $total, $perPage, $page);
    }

    public function count(?string $column = null): int
    {
        return (int) $this->aggregate('COUNT', $column, $this->distinct && $column !== null);
    }

    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * A single column across all matching rows.
     *
     * @return list<mixed>
     */
    public function pluck(string $column): array
    {
        $rows = $this->connection->select(
            $this->toSql([$column]),
            $this->bindings(),
        );

        $key = str_contains($column, '.') ? substr(strrchr($column, '.') ?: '', 1) : $column;

        return array_map(static fn (array $row): mixed => $row[$key] ?? null, $rows);
    }

    // --- writing ----------------------------------------------------------

    /**
     * @param  array<string, mixed> $data
     * @return string The new primary key, as reported by the driver.
     */
    public function insert(array $data, ?string $returning = null): string
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }

        $grammar = $this->connection->grammar();
        $columns = array_map($this->identifier(...), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($this->table),
            implode(', ', $columns),
            $placeholders,
        );

        // Postgres has no reliable lastInsertId(), so ask the INSERT itself.
        if ($returning !== null && $grammar->supportsReturning()) {
            $row = $this->connection->selectOne(
                $sql . $grammar->compileReturning($returning),
                array_values($data),
            );

            return (string) ($row[$returning] ?? '');
        }

        return $this->connection->insert($sql, array_values($data));
    }

    /**
     * @param  array<string, mixed> $data
     * @return int Number of affected rows.
     */
    public function update(array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot update with no columns.');
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->identifier($column) . ' = ?',
            array_keys($data),
        ));

        return $this->connection->statement(
            sprintf(
                'UPDATE %s SET %s%s',
                $this->identifier($this->table),
                $assignments,
                $this->whereClause(),
            ),
            [...array_values($data), ...self::collectBindings($this->wheres)],
        );
    }

    public function delete(): int
    {
        return $this->connection->statement(
            'DELETE FROM ' . $this->identifier($this->table) . $this->whereClause(),
            self::collectBindings($this->wheres),
        );
    }

    // --- compilation ------------------------------------------------------

    /**
     * @param list<string>|null $columns Overrides the selected columns.
     */
    public function toSql(?array $columns = null): string
    {
        $selected = $columns ?? $this->columns;

        $list = implode(', ', array_map(
            fn (string $column): string => $column === '*' ? '*' : $this->identifier($column),
            $selected,
        ));

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $list
            . ' FROM ' . $this->identifier($this->table)
            . $this->joinClause()
            . $this->whereClause()
            . $this->groupClause()
            . $this->havingClause();

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        return $sql . $this->connection->grammar()->compileLimitOffset($this->limit, $this->offset);
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return [...self::collectBindings($this->wheres), ...self::collectBindings($this->havings)];
    }

    /**
     * @param string|null $column   Null aggregates over `*`.
     * @param bool        $distinct Only meaningful with a column.
     */
    private function aggregate(string $function, ?string $column, bool $distinct = false): mixed
    {
        // Aggregating a grouped query counts the groups, not the rows, so the
        // grouped select becomes a subquery.
        if ($this->groups !== []) {
            $inner = $this->toSql();

            // Inside the subquery the columns have lost their table. `SELECT *
            // FROM posts` exposes `views`, not `posts.views`, and the derived
            // table is called `grouped`, so carrying the qualifier through
            // names a table that is not in scope. Every driver rejects it.
            $expression = $this->aggregateExpression(
                $function,
                $column === null ? null : self::withoutQualifier($column),
                $distinct,
            );

            $row = $this->connection->selectOne(
                "SELECT $expression AS aggregate FROM ($inner) AS grouped",
                $this->bindings(),
            );

            return $row['aggregate'] ?? null;
        }

        $sql = 'SELECT ' . $this->aggregateExpression($function, $column, $distinct)
            . ' AS aggregate FROM ' . $this->identifier($this->table)
            . $this->joinClause()
            . $this->whereClause();

        $row = $this->connection->selectOne($sql, self::collectBindings($this->wheres));

        return $row['aggregate'] ?? null;
    }

    private function aggregateExpression(string $function, ?string $column, bool $distinct): string
    {
        $inner = $column === null ? '*' : $this->identifier($column);

        return $function . '(' . ($distinct ? "DISTINCT $inner" : $inner) . ')';
    }

    /**
     * `posts.views` becomes `views`. Only ever applied where the qualifier is
     * known to be out of scope.
     */
    private static function withoutQualifier(string $column): string
    {
        $position = strrpos($column, '.');

        return $position === false ? $column : substr($column, $position + 1);
    }

    private function joinClause(): string
    {
        return $this->joins === [] ? '' : ' ' . implode(' ', $this->joins);
    }

    private function whereClause(): string
    {
        return $this->wheres === [] ? '' : ' WHERE ' . self::renderConditions($this->wheres);
    }

    private function havingClause(): string
    {
        return $this->havings === [] ? '' : ' HAVING ' . self::renderConditions($this->havings);
    }

    private function groupClause(): string
    {
        return $this->groups === [] ? '' : ' GROUP BY ' . implode(', ', $this->groups);
    }

    /**
     * @param list<array{boolean: string, sql: string, bindings: list<mixed>}> $conditions
     */
    private static function renderConditions(array $conditions): string
    {
        $sql = '';

        foreach ($conditions as $index => $condition) {
            $sql .= $index === 0 ? '' : ' ' . strtoupper($condition['boolean']) . ' ';
            $sql .= $condition['sql'];
        }

        return $sql;
    }

    /**
     * @param  list<array{boolean: string, sql: string, bindings: list<mixed>}> $conditions
     * @return list<mixed>
     */
    private static function collectBindings(array $conditions): array
    {
        $bindings = [];

        foreach ($conditions as $condition) {
            foreach ($condition['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    /**
     * @param list<array{boolean: string, sql: string, bindings: list<mixed>}> $target
     */
    private function addComparison(array &$target, string $boolean, string $column, string $operator, mixed $value): self
    {
        $normalised = strtolower(trim($operator));

        if (! in_array($normalised, self::OPERATORS, true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            throw new InvalidArgumentException(sprintf(
                'Unsupported operator [%s]. Use one of: %s.',
                $operator,
                implode(', ', self::OPERATORS),
            ));
        }

        $target[] = [
            'boolean' => $boolean,
            'sql' => $this->identifier($column) . ' ' . strtoupper($normalised) . ' ?',
            'bindings' => [$value],
        ];

        return $this;
    }

    /**
     * Quote an identifier using this connection's dialect.
     *
     * MySQL and SQLite want backticks; Postgres wants double quotes and
     * rejects backticks outright. The grammar knows which.
     */
    private function identifier(string $name): string
    {
        return $this->connection->grammar()->quote($name);
    }
}
