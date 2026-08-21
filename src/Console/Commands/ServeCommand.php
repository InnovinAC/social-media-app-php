<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Phpvin\Application;
use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;

/**
 * Run PHP's built-in server against the public directory.
 *
 *     phpvin serve --port=8080 --host=0.0.0.0
 */
final class ServeCommand implements Command
{
    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return "Run PHP's built-in server for local development";
    }

    public function run(Input $input, Output $output): int
    {
        $host = $input->option('host', '127.0.0.1');
        $port = $input->option('port', '8000');
        $root = $input->option('root', $this->app->basePath('public'));

        if (! is_dir((string) $root)) {
            $output->error("There is no public directory at [$root].");

            return 1;
        }

        $output->success("Serving $root at http://$host:$port");
        $output->muted('Press Ctrl-C to stop.');
        $output->line();

        // Never for production, and the built-in server says so itself by
        // being single-threaded.
        $command = sprintf(
            'php -S %s:%s -t %s',
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $root),
        );

        passthru($command, $exit);

        return $exit;
    }
}
