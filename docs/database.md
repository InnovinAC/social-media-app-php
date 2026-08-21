# Database

[← back to the README](../README.md)

The bundled Active Record layer is registered by a service provider like any
third-party one would be:

```php
'providers' => [
    Phpvin\Database\ActiveRecordProvider::class,
],
```

Remove that line and the framework carries no ORM at all. Write a provider that
registers Doctrine, Eloquent, or your own repositories, and nothing in the core
changes.

## Models

```php
final class Post extends Model
{
    protected static array $fillable = ['title', 'body'];
    protected static array $hidden   = ['internal_notes'];
    protected static array $casts    = ['published' => 'bool', 'meta' => 'array'];
}

$post = Post::create(['title' => 'Hello', 'body' => '...']);
$post->title = 'Hello again';
$post->save();                        // writes only the changed columns
$post->delete();
```

The table name defaults to the snake_case, pluralised class name. Anything
irregular (people, media, criteria) should set `$table`.

**Mass assignment is opt-in.** A model with no `$fillable` accepts nothing, so a
stray `is_admin` field in a form post cannot reach the database. Set those
columns explicitly instead.

## Casts

PDO hands back a string for every column on MySQL, so without a cast an `int`
column round-trips as `'42'` and a `bool` as `'1'`. Declaring the type fixes both
the value you read and the change detection.

```php
protected static array $casts = [
    'published'  => 'bool',
    'view_count' => 'int',
    'rating'     => 'float',
    'meta'       => 'array',      // json in the column, array in PHP
    'published_at' => 'datetime', // DateTimeImmutable
];
```

Without a declared cast, comparison still allows for driver coercion, so
assigning `1` to a column that loaded as `'1'` does not mark the model dirty.

## Querying

```php
Post::query()
    ->where('published', '=', 1)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

Post::find(1);          // ?Post
Post::findOrFail(1);    // throws RecordNotFound
Post::all();
```

Query building always starts at `Post::query()`. There is no `__callStatic`
forwarding, so `Post::where(...)` does not exist and the chain your editor sees
is a real `QueryBuilder`.

A "scope" is just a static method. No framework machinery is needed:

```php
public static function published(): QueryBuilder
{
    return self::query()->where('published', '=', 1);
}
```

### AND, OR, and the trap

Conditions join left to right, exactly as SQL evaluates them. So this:

```php
Post::query()
    ->where('author_id', '=', $me)
    ->where('status', '=', 'draft')
    ->orWhere('status', '=', 'published');
```

means `(author_id = ? AND status = ?) OR status = ?`, and returns every
author's published posts. When you want the other grouping, and with an
ownership filter you almost always do, say so:

```php
Post::query()
    ->where('author_id', '=', $me)
    ->whereGroup(fn (QueryBuilder $q) => $q
        ->where('status', '=', 'draft')
        ->orWhere('status', '=', 'published'));

// author_id = ? AND (status = ? OR status = ?)
```

Groups nest.

### Everything else

```php
->whereIn('id', [1, 2, 3])       ->whereNotIn('status', ['spam'])
->whereNull('deleted_at')        ->whereNotNull('published_at')
->whereBetween('views', 10, 99)
->join('authors', 'posts.author_id', '=', 'authors.id')
->leftJoin(...)                  ->rightJoin(...)
->groupBy('author_id')           ->having('total', '>', 5)
->distinct()                     ->select(['id', 'title'])
```

Aggregates: `count()`, `sum()`, `avg()`, `min()`, `max()`, `exists()`,
`pluck($column)`. Counting a grouped query counts the groups, not the rows.

Values are always bound, never interpolated. Column and table names are
validated against a strict pattern before being quoted, so an identifier can
never be built from user input.

### Ordering a column that can be null

Databases disagree about where a null belongs in a sort, and they disagree
quietly. SQLite and MySQL treat null as the smallest value, so a descending
sort puts nulls at the end. Postgres treats it as the largest, so the same sort
puts them at the front. Nothing errors; you just get a different order, and
with a `limit` on top, different rows.

Say which you want and it is the same everywhere:

```php
Post::query()->orderBy('published_at', 'desc', nulls: 'last');
```

Postgres and SQLite get `NULLS LAST`. MySQL has no such syntax, so it gets the
sort key that means the same thing. Leave `nulls` off and you get the engine's
default, which is fine when the column cannot be null and a trap when it can.

This is the sort of difference that surfaces as "the ordering is wrong on
staging" long after the code was written, so `bin/differential` generates
null-heavy sorts on purpose and requires every driver to agree.

## Pagination

```php
$page = Post::query()->orderBy('id')->paginate(perPage: 20, page: 2);

$page->items;        $page->total;
$page->lastPage();   $page->hasMorePages();
$page->nextPage();   $page->previousPage();
$page->from();       $page->to();
```

It is countable, iterable, and JSON-serialisable, so returning it from a
controller gives you a paginated API response for free.

## Relations

```php
final class Writer extends Model
{
    public function articles(): HasMany { return $this->hasMany(Article::class); }
    public function profile(): HasOne   { return $this->hasOne(Profile::class); }
}

final class Article extends Model
{
    public function writer(): BelongsTo { return $this->belongsTo(Writer::class); }
}
```

Foreign keys default to the snake_case class name plus `_id`; pass your own as
the second argument. Accessing `$writer->articles` resolves lazily and caches.

**Eager load to avoid N+1**, which is one extra query per relation rather than
one per row:

```php
Writer::query()->with('articles', 'profile')->get();   // 3 queries, not 2N+1
```

`Connection::queryCount()` makes that assertable in a test.

## Transactions

```php
$connection->transaction(function (Connection $db): void {
    $order->save();
    $payment->save();
});
```

Rolls back on any throwable. Nested calls use savepoints.

## Migrations

A migration is a PHP file returning a closure:

```php
// database/migrations/001_create_posts_table.php
return function (Connection $db): void {
    $db->statement('CREATE TABLE posts (...)');
};
```

Run them with the console:

```bash
./phpvin migrate
```

Each runs once inside a transaction and is recorded in a `migrations` table, so
running twice is a no-op and a failure stays pending.

**On MySQL the transaction is not the whole story.** MySQL commits implicitly
when it sees `CREATE`, `ALTER` or `DROP`, so a migration that fails halfway
leaves the earlier statements applied and cannot be rolled back. The framework
cannot fix that, only be honest about it: `./phpvin migrate` warns before it
starts, and `Grammar::supportsTransactionalDdl()` tells you in code. Either
way the migration is not recorded, so the retry runs it again, which is why a
MySQL migration is best written to be re-runnable, or kept to one statement.

**Deploying several servers at once is safe on MySQL and Postgres.** A run
holds an advisory lock for its whole duration, so replicas booting together
queue rather than racing: the first applies the migrations and the rest find
nothing pending. The lock belongs to the connection, so a process killed
mid-migration releases it by disconnecting. Waiting is bounded: if something
else has held the lock too long the deploy fails with a message saying so,
rather than hanging.

SQLite has no advisory lock and is left unlocked, which matches how it is
deployed: one machine, one writer.

## Debugging

```php
$connection->enableQueryLog();
// ...
$connection->queryLog();     // every statement with its bindings
$connection->queryCount();   // always on, cheap
```
