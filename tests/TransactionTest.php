<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Transaction bookkeeping, including the case that only shows up on MySQL:
 * DDL commits the open transaction out from under you, so the framework's
 * depth counter and the driver's actual state drift apart.
 */
final class TransactionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('widgets', ['id' => 'id', 'name' => 'string']);
    }

    private function insert(string $name): void
    {
        $this->db->statement(
            'INSERT INTO ' . $this->q('widgets') . ' (' . $this->q('name') . ') VALUES (?)',
            [$name],
        );
    }

    private function rows(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->q('widgets'));

        return (int) ($row['c'] ?? 0);
    }

    #[Test]
    public function a_committed_transaction_keeps_its_rows(): void
    {
        $this->db->transaction(fn () => $this->insert('kept'));

        $this->assertSame(1, $this->rows());
        $this->assertSame(0, $this->db->transactionDepth());
    }

    #[Test]
    public function a_failed_transaction_keeps_nothing(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->insert('doomed');

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->rows());
        $this->assertSame(0, $this->db->transactionDepth());
    }

    #[Test]
    public function the_callbacks_return_value_comes_back(): void
    {
        $this->assertSame('result', $this->db->transaction(fn (): string => 'result'));
    }

    #[Test]
    public function nested_transactions_use_savepoints(): void
    {
        $this->db->transaction(function (): void {
            $this->insert('outer');

            $this->assertSame(1, $this->db->transactionDepth());

            $this->db->transaction(function (): void {
                $this->insert('inner');
                $this->assertSame(2, $this->db->transactionDepth());
            });
        });

        $this->assertSame(2, $this->rows());
        $this->assertSame(0, $this->db->transactionDepth());
    }

    #[Test]
    public function an_inner_failure_rolls_back_only_to_its_savepoint(): void
    {
        $this->db->transaction(function (): void {
            $this->insert('outer');

            try {
                $this->db->transaction(function (): void {
                    $this->insert('inner');

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
                // swallowed, so the outer transaction continues
            }
        });

        $this->assertSame(1, $this->rows(), 'the outer row survived, the inner one did not');
    }

    #[Test]
    public function committing_with_nothing_open_is_a_no_op(): void
    {
        // Used to drive the depth to -1 and emit `RELEASE SAVEPOINT phpvin_sp-1`.
        $this->db->commit();
        $this->db->rollBack();

        $this->assertSame(0, $this->db->transactionDepth());
        $this->assertFalse($this->db->inTransaction());
    }

    #[Test]
    public function ddl_inside_a_transaction_does_not_corrupt_the_depth_counter(): void
    {
        // On MySQL the CREATE TABLE below commits the transaction implicitly.
        // The framework has to notice rather than carry on counting.
        $this->db->transaction(function (): void {
            $this->insert('before ddl');
            $this->createTable('widget_extras', ['id' => 'id']);
        });

        $this->assertSame(0, $this->db->transactionDepth(), 'depth resynced with the driver');
        $this->assertFalse($this->db->inTransaction());

        // And the connection is still usable afterwards.
        $this->insert('after ddl');
        $this->assertSame(2, $this->rows());
    }

    #[Test]
    public function a_begin_after_the_driver_committed_behind_us_starts_fresh(): void
    {
        $this->db->beginTransaction();

        // Exactly what MySQL does when it meets DDL, reproduced here on every
        // driver so the recovery path is not MySQL-only guesswork.
        $this->db->pdo()->commit();

        $this->assertSame(1, $this->db->transactionDepth(), 'our counter has not noticed yet');
        $this->assertFalse($this->db->inTransaction());

        $this->db->beginTransaction();

        // The depth is the tell: a fresh transaction resets it to one. Nesting
        // a savepoint on top of nothing would leave it at two, and the next
        // commit would release a savepoint that was never taken.
        $this->assertSame(1, $this->db->transactionDepth(), 'it started over rather than nesting');
        $this->assertTrue($this->db->inTransaction());

        $this->insert('after the surprise commit');
        $this->db->commit();

        $this->assertSame(0, $this->db->transactionDepth());
        $this->assertSame(1, $this->rows());
    }

    #[Test]
    public function a_php_bool_binding_survives_every_driver(): void
    {
        $this->createTable('flags', ['id' => 'id', 'live' => 'bool']);

        // PDO turns false into '' on the wire, which Postgres rejects outright
        // and MySQL silently stores as zero. Connection normalises first.
        foreach ([true, false] as $value) {
            $this->db->statement(
                'INSERT INTO ' . $this->q('flags') . ' (' . $this->q('live') . ') VALUES (?)',
                [$value],
            );
        }

        $rows = $this->db->select('SELECT ' . $this->q('live') . ' FROM ' . $this->q('flags') . ' ORDER BY ' . $this->q('id'));

        $this->assertSame(1, (int) $rows[0]['live']);
        $this->assertSame(0, (int) $rows[1]['live']);
    }

    #[Test]
    public function a_transaction_can_be_started_again_after_an_implicit_commit(): void
    {
        $this->db->transaction(function (): void {
            $this->createTable('widget_more', ['id' => 'id']);
        });

        $this->db->transaction(fn () => $this->insert('second transaction'));

        $this->assertSame(1, $this->rows());
    }
}
