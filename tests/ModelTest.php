<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Phpvin\Database\Model;
use Phpvin\Database\RecordNotFound;
use RuntimeException;

final class ModelTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('posts', [
            'id' => 'id',
            'title' => 'string',
            'body' => 'text',
            'secret' => 'string',
        ] + self::TIMESTAMPS);
    }

    #[Test]
    public function it_derives_a_table_name_from_the_class_name(): void
    {
        $this->assertSame('posts', Post::table());
        $this->assertSame('categories', Category::table());
        $this->assertSame('blog_entries', BlogEntry::table());
    }

    #[Test]
    public function create_inserts_a_row_and_fills_in_the_primary_key(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->exists());
        $this->assertSame(1, $post->id);
        $this->assertSame(1, Post::query()->count());
    }

    #[Test]
    public function find_returns_a_hydrated_model(): void
    {
        Post::create(['title' => 'Hello', 'body' => 'World']);

        $found = Post::find(1);

        $this->assertInstanceOf(Post::class, $found);
        $this->assertSame('Hello', $found->title);
        $this->assertTrue($found->exists());
    }

    #[Test]
    public function find_returns_null_for_a_missing_row(): void
    {
        $this->assertNull(Post::find(999));
    }

    #[Test]
    public function find_or_fail_throws_for_a_missing_row(): void
    {
        $this->expectException(RecordNotFound::class);

        Post::findOrFail(999);
    }

    #[Test]
    public function save_writes_only_the_columns_that_changed(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $post->title = 'Goodbye';

        $this->assertSame(['title' => 'Goodbye'], $post->changes());
        $this->assertTrue($post->isDirty());

        $post->save();

        $this->assertFalse($post->isDirty());
        $this->assertSame('Goodbye', Post::findOrFail(1)->title);
        $this->assertSame('World', Post::findOrFail(1)->body);
    }

    #[Test]
    public function saving_an_unchanged_model_is_a_no_op(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->save());
        $this->assertFalse($post->isDirty());
    }

    #[Test]
    public function delete_removes_the_row(): void
    {
        $post = Post::create(['title' => 'Hello', 'body' => 'World']);

        $this->assertTrue($post->delete());
        $this->assertFalse($post->exists());
        $this->assertNull(Post::find(1));
    }

    #[Test]
    public function deleting_a_model_that_was_never_saved_does_nothing(): void
    {
        $this->assertFalse((new Post(['title' => 'Draft']))->delete());
    }

    #[Test]
    public function mass_assignment_only_accepts_fillable_columns(): void
    {
        // `secret` is a real column but is not fillable, so a stray form field
        // named `secret` must not reach the database.
        $post = new Post(['title' => 'Hello', 'secret' => 'leaked']);

        $this->assertSame('Hello', $post->title);
        $this->assertNull($post->secret);
    }

    #[Test]
    public function a_model_with_no_fillable_columns_refuses_mass_assignment(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no $fillable columns declared');

        new Locked(['anything' => 'goes']);
    }

    #[Test]
    public function properties_can_still_be_set_one_at_a_time(): void
    {
        $post = new Post();
        $post->title = 'Direct';
        $post->secret = 'allowed when explicit';
        $post->save();

        $this->assertSame('allowed when explicit', Post::findOrFail(1)->secret);
    }

    #[Test]
    public function timestamps_are_maintained(): void
    {
        $post = Post::create(['title' => 'Hello']);

        $this->assertNotNull($post->created_at);
        $this->assertNotNull($post->updated_at);
    }

    #[Test]
    public function the_query_builder_returns_models(): void
    {
        Post::create(['title' => 'First']);
        Post::create(['title' => 'Second']);
        Post::create(['title' => 'Third']);

        $results = Post::query()->orderBy('id', 'desc')->limit(2)->get();

        $this->assertCount(2, $results);
        $this->assertInstanceOf(Post::class, $results[0]);
        $this->assertSame('Third', $results[0]->title);
    }

    #[Test]
    public function queries_can_filter(): void
    {
        Post::create(['title' => 'Keep']);
        Post::create(['title' => 'Drop']);

        $found = Post::query()->where('title', '=', 'Keep')->get();

        $this->assertCount(1, $found);
        $this->assertSame('Keep', $found[0]->title);
    }

    #[Test]
    public function all_returns_every_row(): void
    {
        Post::create(['title' => 'A']);
        Post::create(['title' => 'B']);

        $this->assertCount(2, Post::all());
    }

    #[Test]
    public function hidden_columns_are_left_out_of_to_array(): void
    {
        $post = Post::create(['title' => 'Hello']);
        $post->secret = 'hidden';

        $this->assertArrayNotHasKey('secret', $post->toArray());
        $this->assertArrayHasKey('title', $post->toArray());
    }

    #[Test]
    public function refresh_reloads_from_the_database(): void
    {
        $post = Post::create(['title' => 'Original']);

        Post::query()->where('id', '=', 1)->update(['title' => 'Changed elsewhere']);

        $this->assertSame('Original', $post->title);
        $this->assertSame('Changed elsewhere', $post->refresh()->title);
    }

    #[Test]
    public function a_transaction_rolls_back_on_failure(): void
    {
        try {
            $this->db->transaction(function (): void {
                Post::create(['title' => 'Should not survive']);

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Post::query()->count());
    }

    #[Test]
    public function a_transaction_commits_on_success(): void
    {
        $this->db->transaction(function (): void {
            Post::create(['title' => 'Survives']);
        });

        $this->assertSame(1, Post::query()->count());
    }

    #[Test]
    public function using_a_model_with_no_connection_says_so(): void
    {
        Model::useConnection(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model::useConnection');

        Post::query();
    }
}

class Post extends Model
{
    protected static array $fillable = ['title', 'body'];

    protected static array $hidden = ['secret'];
}

class Category extends Model {}

class BlogEntry extends Model {}

class Locked extends Model {}
