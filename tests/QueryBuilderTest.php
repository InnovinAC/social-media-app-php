<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Database\Connection;
use Phpvin\Database\QueryBuilder;

final class QueryBuilderTest extends TestCase
{
    private function query(string $table = 'posts'): QueryBuilder
    {
        return new QueryBuilder(Connection::sqliteInMemory(), $table);
    }

    #[Test]
    public function it_builds_a_plain_select(): void
    {
        $this->assertSame('SELECT * FROM `posts`', $this->query()->toSql());
    }

    #[Test]
    public function it_binds_values_rather_than_writing_them_into_sql(): void
    {
        $query = $this->query()->where('status', '=', 'published');

        $this->assertSame('SELECT * FROM `posts` WHERE `status` = ?', $query->toSql());
        $this->assertSame(['published'], $query->bindings());
    }

    #[Test]
    public function a_quoted_value_cannot_escape_into_the_sql(): void
    {
        $query = $this->query()->where('title', '=', "'; DROP TABLE posts; --");

        $this->assertSame('SELECT * FROM `posts` WHERE `title` = ?', $query->toSql());
        $this->assertSame(["'; DROP TABLE posts; --"], $query->bindings());
    }

    #[Test]
    public function it_chains_conditions_with_and_and_or(): void
    {
        $query = $this->query()
            ->where('status', '=', 'published')
            ->where('views', '>', 100)
            ->orWhere('pinned', '=', 1);

        $this->assertSame(
            'SELECT * FROM `posts` WHERE `status` = ? AND `views` > ? OR `pinned` = ?',
            $query->toSql(),
        );
        $this->assertSame(['published', 100, 1], $query->bindings());
    }

    #[Test]
    public function where_in_expands_to_one_placeholder_per_value(): void
    {
        $query = $this->query()->whereIn('id', [1, 2, 3]);

        $this->assertSame('SELECT * FROM `posts` WHERE `id` IN (?, ?, ?)', $query->toSql());
        $this->assertSame([1, 2, 3], $query->bindings());
    }

    #[Test]
    public function where_in_with_no_values_matches_nothing_instead_of_being_a_syntax_error(): void
    {
        $query = $this->query()->whereIn('id', []);

        $this->assertSame('SELECT * FROM `posts` WHERE 1 = 0', $query->toSql());
        $this->assertSame([], $query->bindings());
    }

    #[Test]
    public function it_builds_null_checks(): void
    {
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `deleted_at` IS NULL',
            $this->query()->whereNull('deleted_at')->toSql(),
        );
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `published_at` IS NOT NULL',
            $this->query()->whereNotNull('published_at')->toSql(),
        );
    }

    #[Test]
    public function it_applies_ordering_limit_and_offset(): void
    {
        $sql = $this->query()->orderBy('created_at', 'desc')->limit(10)->offset(20)->toSql();

        $this->assertSame('SELECT * FROM `posts` ORDER BY `created_at` DESC LIMIT 10 OFFSET 20', $sql);
    }

    #[Test]
    public function an_offset_without_a_limit_still_produces_valid_sql(): void
    {
        $this->assertStringContainsString('LIMIT', $this->query()->offset(5)->toSql());
    }

    #[Test]
    public function it_selects_named_columns(): void
    {
        $this->assertSame(
            'SELECT `id`, `title` FROM `posts`',
            $this->query()->select(['id', 'title'])->toSql(),
        );
    }

    #[Test]
    public function it_quotes_a_qualified_column(): void
    {
        $this->assertSame(
            'SELECT * FROM `posts` WHERE `posts`.`id` = ?',
            $this->query()->where('posts.id', '=', 1)->toSql(),
        );
    }

    #[Test]
    public function an_injected_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid column');

        $this->query()->where('id = 1 OR 1=1 --', '=', 'x');
    }

    #[Test]
    public function an_unknown_operator_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operator');

        $this->query()->where('id', 'UNION SELECT', 1);
    }

    #[Test]
    public function an_invalid_sort_direction_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->orderBy('id', 'sideways');
    }

    #[Test]
    public function a_negative_limit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->query()->limit(-1);
    }
}
