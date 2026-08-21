<?php

declare(strict_types=1);

namespace Phpvin\Database;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use Phpvin\Database\Relations\BelongsTo;
use Phpvin\Database\Relations\HasMany;
use Phpvin\Database\Relations\HasOne;
use Phpvin\Database\Relations\Relation;

/**
 * A small Active Record base class.
 *
 *     final class Post extends Model
 *     {
 *         protected static array $fillable = ['title', 'body'];
 *         protected static array $casts    = ['published' => 'bool', 'meta' => 'array'];
 *
 *         public function author(): BelongsTo { return $this->belongsTo(User::class); }
 *         public function comments(): HasMany { return $this->hasMany(Comment::class); }
 *
 *         // A "scope" is just a static method. No framework machinery needed.
 *         public static function published(): QueryBuilder
 *         {
 *             return self::query()->where('published', '=', 1);
 *         }
 *     }
 *
 * There is no `__callStatic` forwarding, so `Post::where(...)` does not exist;
 * query building always starts at `Post::query()`. The chain is then a real
 * QueryBuilder your IDE can follow.
 *
 * The static connection below is the one piece of global state in the
 * framework. It is intrinsic to Active Record: `Post::find(1)` has no object
 * to hang a dependency on. Everything else is injected, and tests swap it with
 * `Model::useConnection()`.
 */
/**
 * @phpstan-consistent-constructor
 */
abstract class Model implements JsonSerializable
{
    /** Table name. Defaults to the snake_case, pluralised class name. */
    protected static string $table = '';

    protected static string $primaryKey = 'id';

    /**
     * Columns that may be set from request data. Empty means none: mass
     * assignment is opt-in, so a stray `is_admin` field in a form post cannot
     * reach the database.
     *
     * @var list<string>
     */
    protected static array $fillable = [];

    /** @var list<string> Columns omitted from toArray()/JSON. */
    protected static array $hidden = [];

    /**
     * Column => type. PDO hands back a string for every column on MySQL, so
     * without a cast an `int` column round-trips as `'42'`. Declaring the type
     * fixes both the value you read and the dirty check.
     *
     * Supported: int, float, bool, string, array, json, datetime.
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /** Maintain created_at / updated_at automatically. */
    protected static bool $timestamps = true;

    private static ?Connection $connection = null;

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** @var array<string, mixed> */
    protected array $original = [];

    /** @var array<string, mixed> Loaded relations, by name. */
    protected array $relations = [];

    protected bool $exists = false;

    /**
     * @param array<string, mixed> $attributes Passed through fill(), so only
     *                                         fillable columns are accepted.
     */
    public function __construct(array $attributes = [])
    {
        if ($attributes !== []) {
            $this->fill($attributes);
        }
    }

    // --- connection and identity -----------------------------------------

    public static function useConnection(?Connection $connection): void
    {
        self::$connection = $connection;
    }

    public static function connection(): Connection
    {
        return self::$connection ?? throw new LogicException(
            'No database connection has been set. Call Model::useConnection($connection) during boot.',
        );
    }

    public static function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        return self::pluralise(self::snake(self::baseName(static::class)));
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    // --- querying ---------------------------------------------------------

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::connection(), static::table(), static::class);
    }

    /**
     * Build an instance from a database row, bypassing fillable.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    public static function find(int|string $id): ?static
    {
        /** @var static|null $model */
        $model = static::query()->where(static::$primaryKey, '=', $id)->first();

        return $model;
    }

    public static function findOrFail(int|string $id): static
    {
        return static::find($id) ?? throw RecordNotFound::for(static::class, $id);
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        /** @var list<static> $models */
        $models = static::query()->get();

        return $models;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    // --- relations --------------------------------------------------------

    /**
     * @param class-string<Model> $related
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        return new HasMany(
            $related,
            $foreignKey ?? self::snake(self::baseName(static::class)) . '_id',
            $localKey ?? static::$primaryKey,
            $this,
        );
    }

    /**
     * @param class-string<Model> $related
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        return new HasOne(
            $related,
            $foreignKey ?? self::snake(self::baseName(static::class)) . '_id',
            $localKey ?? static::$primaryKey,
            $this,
        );
    }

    /**
     * @param class-string<Model> $related
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        return new BelongsTo(
            $related,
            $foreignKey ?? self::snake(self::baseName($related)) . '_id',
            $ownerKey ?? $related::primaryKey(),
            $this,
        );
    }

    /**
     * Load relations for a set of models in one query each.
     *
     * Called by QueryBuilder::with(); you rarely call it yourself.
     *
     * @param list<static>  $models
     * @param list<string>  $names
     */
    public static function eagerLoad(array $models, array $names): void
    {
        if ($models === []) {
            return;
        }

        foreach ($names as $name) {
            $relation = $models[0]->newRelation($name);
            $relation->attachTo($models, $name);
        }
    }

    /**
     * @throws LogicException when the named method is not a relation
     */
    public function newRelation(string $name): Relation
    {
        if (! method_exists($this, $name)) {
            throw new LogicException(sprintf(
                '%s has no relation named [%s]. Define a %s() method returning a Relation.',
                static::class,
                $name,
                $name,
            ));
        }

        $relation = $this->{$name}();

        if (! $relation instanceof Relation) {
            throw new LogicException(sprintf(
                '%s::%s() must return a Relation to be eager loaded, got %s.',
                static::class,
                $name,
                get_debug_type($relation),
            ));
        }

        return $relation;
    }

    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    // --- attributes -------------------------------------------------------

    /**
     * Assign the fillable subset of $attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        if (static::$fillable === []) {
            throw new LogicException(sprintf(
                '%s has no $fillable columns declared, so nothing can be mass assigned. '
                . 'Declare `protected static array $fillable = [...]` or set properties one at a time.',
                static::class,
            ));
        }

        foreach (array_intersect_key($attributes, array_flip(static::$fillable)) as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * The raw stored value, before casting. Relations use this for key
     * matching, where the cast form would be the wrong thing to compare.
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** @return array<string, mixed> */
    public function rawAttributes(): array
    {
        return $this->attributes;
    }

    // --- persistence ------------------------------------------------------

    public function save(): bool
    {
        $now = date('Y-m-d H:i:s');

        if ($this->exists) {
            $changes = $this->changes();

            if ($changes === []) {
                return true;
            }

            if (static::$timestamps) {
                $changes['updated_at'] = $this->attributes['updated_at'] = $now;
            }

            static::query()
                ->where(static::$primaryKey, '=', $this->key())
                ->update($this->forStorage($changes));

            $this->original = $this->attributes;

            return true;
        }

        if (static::$timestamps) {
            $this->attributes['created_at'] ??= $now;
            $this->attributes['updated_at'] ??= $now;
        }

        $id = static::query()->insert($this->forStorage($this->attributes), static::$primaryKey);

        if (! isset($this->attributes[static::$primaryKey]) && $id !== '' && $id !== '0') {
            $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
        }

        $this->original = $this->attributes;
        $this->exists = true;

        return true;
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        static::query()
            ->where(static::$primaryKey, '=', $this->key())
            ->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Reload this record's columns from the database.
     */
    public function refresh(): static
    {
        $fresh = static::find($this->key())
            ?? throw RecordNotFound::for(static::class, $this->key());

        $this->attributes = $fresh->attributes;
        $this->original = $fresh->attributes;
        $this->relations = [];

        return $this;
    }

    public function key(): int|string|null
    {
        $key = $this->attributes[static::$primaryKey] ?? null;

        return is_int($key) || is_string($key) ? $key : null;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Attributes that differ from the last loaded or saved state.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                $changes[$key] = $value;

                continue;
            }

            if (! $this->matchesOriginal($key, $value)) {
                $changes[$key] = $value;
            }
        }

        return $changes;
    }

    public function isDirty(): bool
    {
        return $this->changes() !== [];
    }

    /**
     * Whether a value is unchanged, allowing for the driver handing every
     * column back as a string. Without this, `$model->hits = 1` on a row that
     * loaded as `'1'` would rewrite the column on every save.
     */
    private function matchesOriginal(string $key, mixed $value): bool
    {
        $original = $this->original[$key];

        if ($value === $original) {
            return true;
        }

        if ($value === null || $original === null) {
            return false;
        }

        if (isset(static::$casts[$key])) {
            return $this->cast($key, $value) == $this->cast($key, $original);
        }

        if (is_bool($value) || is_bool($original)) {
            return (int) $value === (int) $original;
        }

        if (is_scalar($value) && is_scalar($original)) {
            return (string) $value === (string) $original;
        }

        return false;
    }

    // --- casting ----------------------------------------------------------

    /**
     * Apply the declared cast for reading.
     */
    protected function cast(string $key, mixed $value): mixed
    {
        $type = static::$casts[$key] ?? null;

        if ($type === null || $value === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            // Via the grammar: Postgres can report a boolean as 't'/'f', and
            // filter_var() reads 't' as false.
            'bool', 'boolean' => static::connection()->grammar()->toBool($value),
            'string' => (string) $value,
            'array', 'json' => is_array($value)
                ? $value
                : (json_decode((string) $value, true) ?? []),
            'datetime' => $value instanceof DateTimeInterface
                ? $value
                : new DateTimeImmutable((string) $value),
            default => throw new LogicException(
                sprintf('%s declares an unknown cast [%s] for column [%s].', static::class, $type, $key),
            ),
        };
    }

    /**
     * Reverse the cast for writing.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function forStorage(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $attributes[$key] = match (static::$casts[$key] ?? null) {
                'array', 'json' => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR),
                'bool', 'boolean' => static::connection()->grammar()->fromBool(
                    static::connection()->grammar()->toBool($value),
                ),
                'datetime' => $value instanceof DateTimeInterface
                    ? $value->format('Y-m-d H:i:s')
                    : (string) $value,
                default => $value,
            };
        }

        return $attributes;
    }

    // --- output -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        foreach (array_diff_key($this->attributes, array_flip(static::$hidden)) as $key => $value) {
            $out[$key] = $this->cast($key, $value);
        }

        foreach ($this->relations as $name => $related) {
            $out[$name] = match (true) {
                $related instanceof Model => $related->toArray(),
                is_array($related) => array_map(
                    static fn (mixed $m): mixed => $m instanceof Model ? $m->toArray() : $m,
                    $related,
                ),
                default => $related,
            };
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // --- property access --------------------------------------------------

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->cast($name, $this->attributes[$name]);
        }

        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        // A relation accessed for the first time resolves and caches itself.
        if (method_exists($this, $name)) {
            $relation = $this->{$name}();

            if ($relation instanceof Relation) {
                return $this->relations[$name] = $relation->resolve();
            }
        }

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name], $this->relations[$name]);
    }

    // --- naming -----------------------------------------------------------

    private static function baseName(string $class): string
    {
        return str_contains($class, '\\')
            ? substr(strrchr($class, '\\') ?: '', 1)
            : $class;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Enough English to cover the common cases. Anything irregular (people,
     * media, criteria) should set $table explicitly.
     */
    private static function pluralise(string $word): string
    {
        if (preg_match('/[^aeiou]y$/', $word) === 1) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $word) === 1) {
            return $word . 'es';
        }

        return $word . 's';
    }
}
