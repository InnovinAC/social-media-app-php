<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Database\Connection;
use Phpvin\Database\Model;
use Phpvin\Database\Relations\BelongsTo;
use Phpvin\Database\Relations\HasMany;
use Phpvin\Database\Relations\HasOne;

final class RelationsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Connection::sqliteInMemory();

        $this->connection->statement(
            'CREATE TABLE writers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,
                created_at TEXT, updated_at TEXT)',
        );
        $this->connection->statement(
            'CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, writer_id INT, title TEXT,
                published TEXT, tags TEXT, score TEXT, created_at TEXT, updated_at TEXT)',
        );
        $this->connection->statement(
            'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, writer_id INT, bio TEXT,
                created_at TEXT, updated_at TEXT)',
        );

        Model::useConnection($this->connection);
    }

    protected function tearDown(): void
    {
        Model::useConnection(null);
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

        $this->assertSame('1', $article->getAttribute('published'), 'raw column is a string');
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

        $row = $this->connection->selectOne('SELECT published, tags FROM articles WHERE id = 1');

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
        return $this->connection->queryCount();
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
