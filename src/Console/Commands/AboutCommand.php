<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Phpvin\Application;
use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use Phpvin\Database\Connection;
use Throwable;

/**
 * What this installation actually is.
 *
 * The first thing to run when something behaves differently to how you expect:
 * it answers "which driver", "is debug on", and "where are the views" without
 * reading the config file.
 */
final class AboutCommand implements Command
{
    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'about';
    }

    public function description(): string
    {
        return 'Show versions, drivers and the configuration in effect';
    }

    public function run(Input $input, Output $output): int
    {
        $debug = (bool) $this->app->config('debug');

        $rows = [
            ['phpvin', Application::VERSION],
            ['PHP', PHP_VERSION],
            ['Base path', $this->app->basePath()],
            ['Debug', $debug ? $output->paint('on (never in production)', 'yellow') : 'off'],
            ['Sessions', $this->app->usesSession() ? 'on' : 'off'],
            ['View engine', (string) ($this->app->config('views.engine') ?? 'none')],
            ['Log', (string) ($this->app->config('log.path') ?? 'not configured, so failures are not recorded')],
        ];

        $rows[] = ['Database', $this->describeDatabase()];

        $output->heading('About this application');
        $output->line();
        $output->table(['', ''], $rows);

        return 0;
    }

    private function describeDatabase(): string
    {
        if ($this->app->config('database') === null) {
            return 'none configured';
        }

        try {
            $connection = $this->app->container()->get(Connection::class);

            return $connection->driver() . ' (connected)';
        } catch (Throwable $e) {
            return (string) $this->app->config('database.driver') . ': ' . $e->getMessage();
        }
    }
}
