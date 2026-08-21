<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Database\Grammar\Grammar;
use Phpvin\Database\Grammar\MySqlGrammar;
use Phpvin\Database\Grammar\PostgresGrammar;
use Phpvin\Database\Grammar\SqliteGrammar;

/**
 * The dialect differences, pinned. These are the things that only show up when
 * you run against a database you did not develop on.
 */
final class GrammarTest extends TestCase
{
    #[Test]
    public function it_resolves_a_grammar_per_driver(): void
    {
        $this->assertInstanceOf(SqliteGrammar::class, Grammar::for('sqlite'));
        $this->assertInstanceOf(MySqlGrammar::class, Grammar::for('mysql'));
        $this->assertInstanceOf(PostgresGrammar::class, Grammar::for('pgsql'));
    }

    #[Test]
    public function an_unknown_driver_is_rejected_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No SQL grammar for driver [oracle]');

        Grammar::for('oracle');
    }

    #[Test]
    public function mysql_and_sqlite_use_backticks_and_postgres_uses_double_quotes(): void
    {
        // Postgres rejects backticks outright, which broke every query the
        // builder produced until the grammar existed.
        $this->assertSame('`posts`', Grammar::for('mysql')->quote('posts'));
        $this->assertSame('`posts`', Grammar::for('sqlite')->quote('posts'));
        $this->assertSame('"posts"', Grammar::for('pgsql')->quote('posts'));
    }

    #[Test]
    public function a_qualified_column_is_quoted_part_by_part(): void
    {
        $this->assertSame('`posts`.`id`', Grammar::for('mysql')->quote('posts.id'));
        $this->assertSame('"posts"."id"', Grammar::for('pgsql')->quote('posts.id'));
    }

    #[Test]
    public function every_grammar_refuses_an_injected_identifier(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            try {
                Grammar::for($driver)->quote('id = 1 OR 1=1 --');
                $this->fail("$driver accepted an injected identifier");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not a valid column', $e->getMessage());
            }
        }
    }

    #[Test]
    public function mysql_needs_a_limit_beside_an_offset_and_postgres_does_not(): void
    {
        $this->assertSame(' LIMIT 10 OFFSET 20', Grammar::for('mysql')->compileLimitOffset(10, 20));

        // MySQL and SQLite reject a bare OFFSET, so a stand-in LIMIT appears.
        $this->assertStringContainsString('LIMIT', Grammar::for('mysql')->compileLimitOffset(null, 20));
        $this->assertSame(' OFFSET 20', Grammar::for('pgsql')->compileLimitOffset(null, 20));
    }

    #[Test]
    public function no_clause_is_emitted_without_a_limit_or_offset(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $this->assertSame('', Grammar::for($driver)->compileLimitOffset(null, null));
        }
    }

    #[Test]
    public function only_postgres_returns_the_new_key_from_the_insert(): void
    {
        // pgsql's lastInsertId() falls back to lastval(), which is per-session
        // and wrong as soon as a trigger touches another sequence.
        $this->assertTrue(Grammar::for('pgsql')->supportsReturning());
        $this->assertFalse(Grammar::for('mysql')->supportsReturning());
        $this->assertFalse(Grammar::for('sqlite')->supportsReturning());

        $this->assertSame(' RETURNING "id"', Grammar::for('pgsql')->compileReturning('id'));
    }

    #[Test]
    public function only_mysql_cannot_roll_back_ddl(): void
    {
        $this->assertFalse(Grammar::for('mysql')->supportsTransactionalDdl());
        $this->assertTrue(Grammar::for('pgsql')->supportsTransactionalDdl());
        $this->assertTrue(Grammar::for('sqlite')->supportsTransactionalDdl());
    }

    #[Test]
    public function postgres_style_booleans_are_read_correctly(): void
    {
        $grammar = Grammar::for('pgsql');

        // filter_var('t', FILTER_VALIDATE_BOOL) is false, which is exactly the
        // trap this exists to avoid.
        $this->assertTrue($grammar->toBool('t'));
        $this->assertFalse($grammar->toBool('f'));
        $this->assertTrue($grammar->toBool(true));
        $this->assertFalse($grammar->toBool(false));
    }

    #[Test]
    public function the_usual_truthy_spellings_are_understood_everywhere(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $grammar = Grammar::for($driver);

            foreach (['1', 'true', 'yes', 'on', 'TRUE'] as $truthy) {
                $this->assertTrue($grammar->toBool($truthy), "$driver: $truthy");
            }

            foreach (['0', 'false', 'no', 'off', ''] as $falsy) {
                $this->assertFalse($grammar->toBool($falsy), "$driver: $falsy");
            }
        }
    }

    #[Test]
    public function every_grammar_stores_a_boolean_as_an_integer(): void
    {
        // Not a PHP bool: PDO binds false as an empty string, which Postgres
        // rejects for boolean *and* smallint columns. 0/1 is accepted by every
        // supported driver for both.
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $this->assertSame(1, Grammar::for($driver)->fromBool(true), $driver);
            $this->assertSame(0, Grammar::for($driver)->fromBool(false), $driver);
        }
    }
}
