<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Phpvin\Database\Model;
use Phpvin\Database\Relations\BelongsTo;
use Phpvin\Database\Relations\HasMany;
use Phpvin\Database\Relations\HasOne;

final class RelationsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('writers', ['id' => 'id', 'name' => 'string'] + self::TIMESTAMPS);
        $this->createTable('articles', [
            'id' => 'id',
            'writer_id' => 'int',
            'title' => 'string',
            'published' => 'bool',
            'tags' => 'text',
            'score' => 'float',
        ] + self::TIMESTAMPS);
        $this->createTable('profiles', ['id' => 'id', 'writer_id' => 'int', 'bio' => 'text'] + self::TIMESTAMPS);
    }

    private function seed(): Writer
    {
        $writer = Writer::create(['name' => 'ada']);

        foreach (['First', 'Second'] as $title) {
            $article = new Article(['title' => $title, 'published' => true, 'tags' => ['php']]);
            $article->writer_id = $writer->key();
            $article->save();
        }

        $profile = new Profile(['bio' => 'writes things']);
        $profile->writer_id = $writer->key();
        $profile->save();

        return $writer;
    }

    // --- casts ------------------------------------------------------------

    #[Test]
    public function a_bool_cast_survives_the_round_trip(): void
    {
        $this->seed();

        $article = Article::findOrFail(1);

        // What the driver hands back for a boolean column is its own business:
        // '1' on MySQL, 1 on SQLite, true or 't' on Postgres. The invariant is
        // that the cast turns all of them into a real bool.
        $this->assertNotSame(true, $article->getAttribute('published'), 'the raw value is whatever the driver stored');
        $this->assertTrue($article->published, 'cast read gives a real bool');
    }

    #[Test]
    public function an_array_cast_round_trips_as_json(): void
    {
        $this->seed();

        $this->assertSame(['php'], Article::findOrFail(1)->tags);
    }

    #[Test]
    public function reassigning_the_same_logical_value_leaves_the_model_clean(): void
    {
        $this->seed();
        $article = Article::findOrFail(1);

        $article->published = true;   // stored as '1'
        $article->title = 'First';    // unchanged string

        $this->assertFalse($article->isDirty());
        $this->assertSame([], $article->changes());
    }

    #[Test]
    public function an_int_like_string_from_the_driver_is_not_a_change(): void
    {
        $this->seed();
        $article = Article::findOrFail(1);

        // PDO hands back '1' for an int column on MySQL; assigning 1 is a no-op.
        $article->writer_id = 1;

        $this->assertFalse($article->isDirty());
    }

    #[Test]
    public function a_real_change_is_still_detected(): void
    {
        $this->seed();
        $article = Article::findOrFail(1);

        $article->title = 'Renamed';
        $article->published = false;

        $this->assertSame(['title' => 'Renamed', 'published' => false], $article->changes());
    }

    #[Test]
    public function a_cast_value_is_written_back_in_storage_form(): void
    {
        $this->seed();
        $article = Article::findOrFail(1);

        $article->tags = ['php', 'testing'];
        $article->published = false;
        $article->save();

        $row = $this->db->selectOne('SELECT published, tags FROM articles WHERE id = 1');

        $this->assertSame('0', (string) $row['published']);
        $this->assertSame('["php","testing"]', $row['tags']);
        $this->assertSame(['php', 'testing'], Article::findOrFail(1)->tags);
    }

    #[Test]
    public function to_array_returns_cast_values(): void
    {
        $this->seed();

        $array = Article::findOrFail(1)->toArray();

        $this->assertTrue($array['published']);
        $this->assertSame(['php'], $array['tags']);
    }

    #[Test]
    public function an_unknown_cast_type_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unknown cast');

        Broken::hydrate(['id' => 1, 'thing' => 'x'])->toArray();
    }

    // --- relations --------------------------------------------------------

    #[Test]
    public function has_many_resolves_lazily(): void
    {
        $writer = $this->seed();

        $articles = Writer::findOrFail($writer->key())->articles;

        $this->assertCount(2, $articles);
        $this->assertInstanceOf(Article::class, $articles[0]);
    }

    #[Test]
    public function has_one_resolves_to_a_single_model(): void
    {
        $writer = $this->seed();

        $this->assertInstanceOf(Profile::class, Writer::findOrFail($writer->key())->profile);
        $this->assertSame('writes things', Writer::findOrFail($writer->key())->profile->bio);
    }

    #[Test]
    public function belongs_to_resolves_the_owner(): void
    {
        $this->seed();

        $this->assertSame('ada', Article::findOrFail(1)->writer->name);
    }

    #[Test]
    public function belongs_to_is_null_when_the_key_is_null(): void
    {
        $orphan = new Article(['title' => 'Nobody']);
        $orphan->save();

        $this->assertNull(Article::findOrFail($orphan->key())->writer);
    }

    #[Test]
    public function a_resolved_relation_is_cached_on_the_model(): void
    {
        $this->seed();
        $article = Article::findOrFail(1);

        $this->assertFalse($article->relationLoaded('writer'));

        $article->writer;

        $this->assertTrue($article->relationLoaded('writer'));
    }

    // --- eager loading ----------------------------------------------------

    #[Test]
    public function eager_loading_attaches_children_to_every_parent(): void
    {
        $this->seed();
        Writer::create(['name' => 'grace']);

        $writers = Writer::query()->orderBy('id')->with('articles')->get();

        $this->assertTrue($writers[0]->relationLoaded('articles'));
        $this->assertCount(2, $writers[0]->articles);
        $this->assertSame([], $writers[1]->articles, 'a parent with no children gets an empty list');
    }

    #[Test]
    public function eager_loading_uses_one_query_per_relation_not_one_per_row(): void
    {
        $this->seed();
        Writer::create(['name' => 'grace']);
        Writer::create(['name' => 'edsger']);

        $before = $this->countQueries();
        Writer::query()->with('articles', 'profile')->get();
        $queries = $this->countQueries() - $before;

        // One for the writers, one for the articles, one for the profiles.
        $this->assertSame(3, $queries);
    }

    #[Test]
    public function eager_loading_belongs_to_collapses_the_owner_lookup(): void
    {
        $this->seed();

        $before = $this->countQueries();
        $articles = Article::query()->with('writer')->get();
        $queries = $this->countQueries() - $before;

        $this->assertSame(2, $queries);
        $this->assertSame('ada', $articles[0]->writer->name);
        $this->assertSame('ada', $articles[1]->writer->name);
    }

    #[Test]
    public function eager_loaded_relations_appear_in_to_array(): void
    {
        $this->seed();

        $array = Writer::query()->with('articles')->get()[0]->toArray();

        $this->assertCount(2, $array['articles']);
        $this->assertSame('First', $array['articles'][0]['title']);
    }

    #[Test]
    public function eager_loading_an_unknown_relation_says_so(): void
    {
        $this->seed();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no relation named [nonsense]');

        Writer::query()->with('nonsense')->get();
    }

    #[Test]
    public function eager_loading_a_method_that_is_not_a_relation_says_so(): void
    {
        $this->seed();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return a Relation');

        Writer::query()->with('name')->get();
    }

    /**
     * The connection counts its own statements, which makes "is this N+1?" an
     * assertion rather than an eyeball exercise.
     */
    private function countQueries(): int
    {
        return $this->db->queryCount();
    }

    // --- eager loading must agree with lazy loading -----------------------

    /**
     * Datasets chosen for the shapes that break key matching, not for realism.
     *
     * @return array<string, array{0: list<array{0: int|null, 1: int}>}>
     */
    public static function shapes(): array
    {
        // [writer index or null for a dangling id, how many articles]
        return [
            'nobody has anything' => [[]],
            'one writer, no children' => [[]],
            'a writer with one child' => [[[0, 1]]],
            'a writer with many children' => [[[0, 5]]],
            'children spread over writers' => [[[0, 2], [1, 3], [2, 1]]],
            'a writer in the middle has none' => [[[0, 2], [2, 2]]],
            'orphans with a null key' => [[[0, 2], [null, 3]]],
            'only orphans' => [[[null, 4]]],
            'a dangling foreign key' => [[[0, 1], [99, 2]]],
            'every writer has children' => [[[0, 1], [1, 1], [2, 1]]],
        ];
    }

    /**
     * @param list<array{0: int|null, 1: int}> $plan
     */
    #[Test]
    #[DataProvider('shapes')]
    public function eager_loading_returns_exactly_what_lazy_loading_returns(array $plan): void
    {
        // The strongest oracle available here: `with()` is an optimisation, so
        // it has to be invisible. Anything it returns that resolving the
        // relation one parent at a time would not is a bug, whichever of the
        // two happens to be right. Hand-written relation tests check the happy
        // shape; the shapes that break key matching are orphans, gaps, and
        // parents with nothing, and those are enumerated here instead.
        $writers = [];

        foreach (['ada', 'grace', 'alan'] as $name) {
            $writers[] = Writer::create(['name' => $name]);
        }

        foreach ($plan as [$which, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $article = new Article(['title' => "t$i", 'published' => true, 'tags' => []]);

                $article->writer_id = match (true) {
                    $which === null => null,
                    $which === 99 => 4242,          // points at a writer that is not there
                    default => $writers[$which]->key(),
                };

                $article->save();
            }
        }

        // Eager: one query for the parents, one for the relation.
        $eager = Writer::query()->orderBy('id')->with('articles')->get();

        // Lazy: resolved one parent at a time, from fresh models.
        $lazy = Writer::query()->orderBy('id')->get();

        $this->assertCount(count($lazy), $eager);

        foreach ($eager as $index => $writer) {
            $this->assertSame(
                $this->keysOf($lazy[$index]->articles),
                $this->keysOf($writer->articles),
                "articles disagreed for writer {$writer->key()}",
            );
        }
    }

    /**
     * @param list<array{0: int|null, 1: int}> $plan
     */
    #[Test]
    #[DataProvider('shapes')]
    public function eager_loading_belongs_to_agrees_with_lazy(array $plan): void
    {
        $writers = [];

        foreach (['ada', 'grace', 'alan'] as $name) {
            $writers[] = Writer::create(['name' => $name]);
        }

        foreach ($plan as [$which, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $article = new Article(['title' => "t$i", 'published' => true, 'tags' => []]);

                $article->writer_id = match (true) {
                    $which === null => null,
                    $which === 99 => 4242,
                    default => $writers[$which]->key(),
                };

                $article->save();
            }
        }

        $eager = Article::query()->orderBy('id')->with('writer')->get();
        $lazy = Article::query()->orderBy('id')->get();

        // Stated even when there are no articles: "both sides returned
        // nothing" is a real agreement, not an absent one.
        $this->assertCount(count($lazy), $eager);

        foreach ($eager as $index => $article) {
            $expected = $lazy[$index]->writer;

            $this->assertSame(
                $expected === null ? null : $expected->key(),
                $article->writer === null ? null : $article->writer->key(),
                "writer disagreed for article {$article->key()}",
            );
        }
    }

    #[Test]
    public function eager_loading_a_has_one_picks_the_same_row_as_lazy_loading(): void
    {
        // A hasOne with two matching rows is a data problem, not a supported
        // shape -- but it happens, and when it does the two loading paths must
        // not disagree about which row wins. Eager takes the first of a
        // whereIn result; lazy takes the first of a `= ?` result. Those are
        // different queries, and without an order nothing makes them agree.
        $writer = Writer::create(['name' => 'ada']);

        foreach (['first bio', 'second bio'] as $bio) {
            $profile = new Profile(['bio' => $bio]);
            $profile->writer_id = $writer->key();
            $profile->save();
        }

        $profiles = Profile::query()->orderBy('id')->get();
        $first = $profiles[0]->key();

        $eager = Writer::query()->with('profile')->get()[0];
        $lazy = Writer::findOrFail($writer->key());

        $this->assertSame(
            $lazy->profile?->key(),
            $eager->profile?->key(),
            'eager and lazy chose different rows for a hasOne',
        );

        // Asserting *which* row, not just that the two agree. Agreement alone
        // passes while both sides are accidentally returning insertion order,
        // and goes on passing right up until an engine reorders them.
        $this->assertSame($first, $eager->profile?->key());
        $this->assertSame($first, $lazy->profile?->key());
    }

    /**
     * @param  list<Model> $models
     * @return list<int|string>
     */
    private function keysOf(array $models): array
    {
        return array_map(static fn (Model $m): int|string => $m->key(), $models);
    }
}

class Writer extends Model
{
    protected static string $table = 'writers';

    protected static array $fillable = ['name'];

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function name(): string
    {
        return 'not a relation';
    }
}

class Article extends Model
{
    protected static string $table = 'articles';

    protected static array $fillable = ['title', 'published', 'tags'];

    protected static array $casts = ['published' => 'bool', 'tags' => 'array', 'score' => 'float'];

    public function writer(): BelongsTo
    {
        return $this->belongsTo(Writer::class);
    }
}

class Profile extends Model
{
    protected static string $table = 'profiles';

    protected static array $fillable = ['bio'];
}

class Broken extends Model
{
    protected static string $table = 'broken';

    protected static array $casts = ['thing' => 'wibble'];
}
