<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use Phpvin\Database\Connection;
use Phpvin\Database\Migrator;

/**
 * Apply pending migrations, or just say what is pending.
 *
 *     phpvin migrate
 *     phpvin migrate --pending
 */
final class MigrateCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Connection $connection,
    ) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply pending migrations';
    }

    public function run(Input $input, Output $output): int
    {
        $pending = $this->migrator->pending();

        if ($input->flag('pending')) {
            if ($pending === []) {
                $output->success('Nothing pending.');

                return 0;
            }

            $output->heading(sprintf('%d pending:', count($pending)));

            foreach (array_keys($pending) as $name) {
                $output->line('  ' . $name);
            }

            return 0;
        }

        if ($pending === []) {
            $output->success('Nothing to migrate.');

            return 0;
        }

        if (! $this->connection->grammar()->supportsTransactionalDdl()) {
            // Worth knowing before, not after: on MySQL a migration that fails
            // halfway leaves the earlier statements applied.
            $output->warn($this->connection->driver() . ' cannot roll back DDL. A failure will leave a partial schema.');
        }

        $applied = $this->migrator->run();

        foreach ($applied as $name) {
            $output->line('  ' . $output->paint('migrated', 'green') . '  ' . $name);
        }

        $output->line();
        $output->success(sprintf('%d migration%s applied.', count($applied), count($applied) === 1 ? '' : 's'));

        return 0;
    }
}
