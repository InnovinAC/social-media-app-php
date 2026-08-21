<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Phpvin\Database\QueryBuilder;

/**
 * Grouping, joins, aggregates and pagination: the surface added after the
 * first release, plus the precedence trap that prompted it.
 */
final class QuerySurfaceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('authors', ['id' => 'id', 'name' => 'string']);
        $this->createTable('posts', [
            'id' => 'id', 'author_id' => 'int', 'status' => 'string', 'views' => 'int',
        ]);

        foreach ([[1, 'ada'], [2, 'grace']] as [$id, $name]) {
            $this->db->statement(
                'INSERT INTO ' . $this->q('authors') . ' (' . $this->q('id') . ', ' . $this->q('name') . ') VALUES (?, ?)',
                [$id, $name],
            );
        }

        foreach ([[1, 'draft', 10], [1, 'published', 5], [1, 'archived', 1], [2, 'published', 7], [2, 'draft', 3]] as $row) {
            $this->db->statement(
                'INSERT INTO ' . $this->q('posts') . ' (' . $this->q('author_id') . ', '
                . $this->q('status') . ', ' . $this->q('views') . ') VALUES (?, ?, ?)',
                $row,
            );
        }
    }

    private function posts(): QueryBuilder
    {
        return new QueryBuilder($this->db, 'posts');
    }

    // --- the precedence trap ----------------------------------------------

    #[Test]
    public function an_ungrouped_or_still_reads_left_to_right(): void
    {
        // Documented behaviour, matching how SQL itself evaluates it.
        $query = $this->posts()
            ->where('author_id', '=', 1)
            ->where('status', '=', 'draft')
            ->orWhere('status', '=', 'published');

        $this->assertSame(
            $this->sql('SELECT * FROM `posts` WHERE `author_id` = ? AND `status` = ? OR `status` = ?'),
            $query->toSql(),
        );
    }

    #[Test]
    public function a_group_keeps_the_or_from_escaping_the_owner_filter(): void
    {
        $query = $this->posts()
            ->where('author_id', '=', 1)
            ->whereGroup(fn (QueryBuilder $q) => $q
                ->where('status', '=', 'draft')
                ->orWhere('status', '=', 'published'));

        $this->assertSame(
            $this->sql('SELECT * FROM `posts` WHERE `author_id` = ? AND (`status` = ? OR `status` = ?)'),
            $query->toSql(),
        );
        $this->assertSame([1, 'draft', 'published'], $query->bindings());
    }

    #[Test]
    public function the_grouped_query_returns_only_the_owners_rows(): void
    {
        $rows = $this->posts()
            ->where('author_id', '=', 1)
            ->whereGroup(fn (QueryBuilder $q) => $q
                ->where('status', '=', 'draft')
                ->orWhere('status', '=', 'published'))
            ->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row['author_id'], 'no other author leaked in');
        }
    }

    #[Test]
    public function groups_nest(): void
    {
        $query = $this->posts()
            ->where('views', '>', 0)
            ->whereGroup(fn (QueryBuilder $q) => $q
                ->where('status', '=', 'draft')
                ->orWhereGroup(fn (QueryBuilder $inner) => $inner
                    ->where('status', '=', 'published')
                    ->where('views', '>', 6)));

        $this->assertSame(
            $this->sql('SELECT * FROM `posts` WHERE `views` > ? AND (`status` = ? OR (`status` = ? AND `views` > ?))'),
            $query->toSql(),
        );
    }

    #[Test]
    public function an_empty_group_adds_nothing(): void
    {
        $query = $this->posts()->where('id', '=', 1)->whereGroup(fn () => null);

        $this->assertSame($this->sql('SELECT * FROM `posts` WHERE `id` = ?'), $query->toSql());
    }

    // --- more conditions --------------------------------------------------

    #[Test]
    public function where_not_in_excludes(): void
    {
        $rows = $this->posts()->whereNotIn('status', ['draft', 'archived'])->get();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function where_not_in_with_no_values_excludes_nothing(): void
    {
        $this->assertCount(5, $this->posts()->whereNotIn('status', [])->get());
    }

    #[Test]
    public function where_between_is_inclusive(): void
    {
        $this->assertCount(3, $this->posts()->whereBetween('views', 3, 7)->get());
    }

    // --- joins ------------------------------------------------------------

    #[Test]
    public function an_inner_join_reaches_the_other_table(): void
    {
        $rows = $this->posts()
            ->select(['posts.id', 'authors.name'])
            ->join('authors', 'posts.author_id', '=', 'authors.id')
            ->where('authors.name', '=', 'grace')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('grace', $rows[0]['name']);
    }

    #[Test]
    public function a_left_join_keeps_unmatched_rows(): void
    {
        $this->db->statement("INSERT INTO posts (author_id, status, views) VALUES (99, 'draft', 0)");

        $rows = (new QueryBuilder($this->db, 'posts'))
            ->select(['posts.id', 'authors.name'])
            ->leftJoin('authors', 'posts.author_id', '=', 'authors.id')
            ->get();

        $this->assertCount(6, $rows);
        $this->assertNull($rows[5]['name']);
    }

    #[Test]
    public function an_unknown_join_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->posts()->join('authors', 'posts.author_id', '=', 'authors.id', 'sideways');
    }

    #[Test]
    public function a_join_cannot_smuggle_sql_through_a_column_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid column');

        $this->posts()->join('authors', 'posts.author_id', '=', 'authors.id OR 1=1');
    }

    // --- grouping and aggregates ------------------------------------------

    #[Test]
    public function group_by_collapses_rows(): void
    {
        $rows = $this->posts()
            ->select(['author_id'])
            ->groupBy('author_id')
            ->get();

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function having_filters_the_groups(): void
    {
        $query = $this->posts()->select(['author_id'])->groupBy('author_id')->having('author_id', '>', 1);

        $this->assertStringContainsString($this->sql('GROUP BY `author_id` HAVING `author_id` > ?'), $query->toSql());
        $this->assertCount(1, $query->get());
    }

    #[Test]
    public function counting_a_grouped_query_counts_the_groups(): void
    {
        // Groups, not rows. That is what grouping is for.
        $this->assertSame(2, $this->posts()->select(['author_id'])->groupBy('author_id')->count());
    }

    #[Test]
    public function the_aggregates_add_up(): void
    {
        $this->assertSame(26.0, $this->posts()->sum('views'));
        $this->assertSame(5.2, round($this->posts()->avg('views'), 4));
        $this->assertSame(1, (int) $this->posts()->min('views'));
        $this->assertSame(10, (int) $this->posts()->max('views'));
    }

    #[Test]
    public function an_aggregate_respects_the_where_clause(): void
    {
        $this->assertSame(16.0, $this->posts()->where('author_id', '=', 1)->sum('views'));
    }

    #[Test]
    public function an_aggregate_respects_a_join(): void
    {
        $count = $this->posts()
            ->join('authors', 'posts.author_id', '=', 'authors.id')
            ->where('authors.name', '=', 'ada')
            ->count();

        $this->assertSame(3, $count);
    }

    #[Test]
    public function distinct_counts_unique_values(): void
    {
        $this->assertSame(3, $this->posts()->distinct()->count('status'));
    }

    // --- pluck ------------------------------------------------------------

    #[Test]
    public function pluck_returns_one_column(): void
    {
        $names = (new QueryBuilder($this->db, 'authors'))->orderBy('id')->pluck('name');

        $this->assertSame(['ada', 'grace'], $names);
    }

    #[Test]
    public function pluck_handles_a_qualified_column(): void
    {
        $names = $this->posts()
            ->join('authors', 'posts.author_id', '=', 'authors.id')
            ->orderBy('posts.id')
            ->pluck('authors.name');

        $this->assertSame('ada', $names[0]);
    }

    // --- pagination -------------------------------------------------------

    #[Test]
    public function it_returns_one_page_with_the_totals(): void
    {
        $page = $this->posts()->orderBy('id')->paginate(2, 1);

        $this->assertCount(2, $page);
        $this->assertSame(5, $page->total);
        $this->assertSame(3, $page->lastPage());
        $this->assertTrue($page->onFirstPage());
        $this->assertTrue($page->hasMorePages());
        $this->assertSame(2, $page->nextPage());
        $this->assertNull($page->previousPage());
        $this->assertSame(1, $page->from());
        $this->assertSame(2, $page->to());
    }

    #[Test]
    public function the_last_page_reports_no_more(): void
    {
        $page = $this->posts()->orderBy('id')->paginate(2, 3);

        $this->assertCount(1, $page);
        $this->assertFalse($page->hasMorePages());
        $this->assertNull($page->nextPage());
        $this->assertSame(2, $page->previousPage());
    }

    #[Test]
    public function pagination_respects_the_filters(): void
    {
        $page = $this->posts()->where('author_id', '=', 1)->paginate(2, 1);

        $this->assertSame(3, $page->total, 'the count uses the same where clause');
    }

    #[Test]
    public function a_page_past_the_end_is_empty_rather_than_broken(): void
    {
        $page = $this->posts()->paginate(2, 99);

        $this->assertTrue($page->isEmpty());
        $this->assertNull($page->from());
    }

    #[Test]
    public function nonsense_page_numbers_are_clamped(): void
    {
        $page = $this->posts()->paginate(0, -5);

        $this->assertSame(1, $page->currentPage);
        $this->assertSame(1, $page->perPage);
    }

    #[Test]
    public function a_page_serialises_for_an_api(): void
    {
        $payload = json_decode(json_encode($this->posts()->orderBy('id')->paginate(2, 1)), true);

        $this->assertSame([2, 5, 1, 3], [
            count($payload['data']),
            $payload['total'],
            $payload['current_page'],
            $payload['last_page'],
        ]);
    }

    // --- first() no longer mutates ----------------------------------------

    #[Test]
    public function first_does_not_cap_a_builder_the_caller_reuses(): void
    {
        $query = $this->posts()->orderBy('id');

        $query->first();

        $this->assertCount(5, $query->get(), 'the LIMIT 1 was scoped to first()');
    }

    // --- query counting ---------------------------------------------------

    #[Test]
    public function the_connection_counts_its_statements(): void
    {
        $before = $this->db->queryCount();

        $this->posts()->get();
        $this->posts()->count();

        $this->assertSame(2, $this->db->queryCount() - $before);
    }

    #[Test]
    public function the_query_log_records_sql_and_bindings_when_enabled(): void
    {
        $this->db->enableQueryLog();
        $this->posts()->where('status', '=', 'draft')->get();

        $log = $this->db->queryLog();

        $this->assertNotEmpty($log);
        $this->assertStringContainsString($this->sql('WHERE `status` = ?'), end($log)['sql']);
        $this->assertSame(['draft'], end($log)['bindings']);
    }

    // --- cross-driver agreement -------------------------------------------

    #[Test]
    public function an_aggregate_over_a_grouped_query_accepts_a_qualified_column(): void
    {
        // The grouped select becomes a subquery aliased `grouped`, inside
        // which `posts.views` names a table that is no longer in scope. Every
        // driver rejected it, so this was broken everywhere at once rather
        // than being a portability wrinkle -- which is why no cross-driver
        // test caught it and a generated one did.
        $query = fn (): QueryBuilder => (new QueryBuilder($this->db, 'posts'))
            ->select(['posts.views'])
            ->groupBy('posts.views');

        $this->assertSame(5, $query()->count('posts.views'));
        $this->assertSame(26.0, $query()->sum('posts.views'));
        $this->assertSame(10, (int) $query()->max('posts.views'));
        $this->assertSame(1, (int) $query()->min('posts.views'));
    }

    #[Test]
    public function the_unqualified_form_still_works_when_grouped(): void
    {
        $query = (new QueryBuilder($this->db, 'posts'))
            ->select(['views'])
            ->groupBy('views');

        $this->assertSame(5, $query->count('views'));
    }

    #[Test]
    public function nulls_can_be_placed_explicitly_and_land_the_same_way_everywhere(): void
    {
        // SQLite and MySQL sort null as the smallest value, Postgres as the
        // largest, so `orderBy('score', 'desc')` puts nulls at opposite ends
        // depending on the driver -- silently, and visibly only once a limit
        // starts cutting the result somewhere different. Asking for a
        // placement is how you stop that being the driver's decision.
        $this->createTable('scores', ['id' => 'id', 'score' => 'int']);

        foreach ([10, null, 30, null, 20] as $score) {
            $this->db->statement(
                'INSERT INTO ' . $this->q('scores') . ' (' . $this->q('score') . ') VALUES (?)',
                [$score],
            );
        }

        $ordered = static fn (array $rows): string => implode(',', array_map(
            static fn (array $row): string => $row['score'] === null ? 'NULL' : (string) (int) $row['score'],
            $rows,
        ));

        $descLast = (new QueryBuilder($this->db, 'scores'))
            ->orderBy('score', 'desc', nulls: 'last')->orderBy('id')->get();

        $descFirst = (new QueryBuilder($this->db, 'scores'))
            ->orderBy('score', 'desc', nulls: 'first')->orderBy('id')->get();

        $ascFirst = (new QueryBuilder($this->db, 'scores'))
            ->orderBy('score', 'asc', nulls: 'first')->orderBy('id')->get();

        $this->assertSame('30,20,10,NULL,NULL', $ordered($descLast));
        $this->assertSame('NULL,NULL,30,20,10', $ordered($descFirst));
        $this->assertSame('NULL,NULL,10,20,30', $ordered($ascFirst));
    }

    #[Test]
    public function distinct_applies_to_count_and_not_to_the_other_aggregates(): void
    {
        // `distinct()` narrows what is being counted, which is what people
        // reach for it to do. It deliberately does not reach SUM or AVG: a
        // distinct sum is a different question, and one you should have to ask
        // for in so many words rather than get as a side effect of a call
        // further up the chain.
        //
        // Every value here repeats, because with distinct data the two forms
        // agree and the distinction is invisible.
        $this->createTable('votes', ['id' => 'id', 'weight' => 'int']);

        foreach ([5, 5, 5, 10, 10] as $weight) {
            $this->db->statement(
                'INSERT INTO ' . $this->q('votes') . ' (' . $this->q('weight') . ') VALUES (?)',
                [$weight],
            );
        }

        $query = fn (): QueryBuilder => new QueryBuilder($this->db, 'votes');

        $this->assertSame(5, $query()->count('weight'));
        $this->assertSame(2, $query()->distinct()->count('weight'));

        // 35, not the 15 a distinct sum would give.
        $this->assertSame(35.0, $query()->sum('weight'));
        $this->assertSame(35.0, $query()->distinct()->sum('weight'));
        $this->assertSame(7.0, $query()->distinct()->avg('weight'));
    }

    #[Test]
    public function a_null_placement_that_is_not_first_or_last_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('first or last');

        (new QueryBuilder($this->db, 'posts'))->orderBy('views', 'asc', nulls: 'middle');
    }
}
