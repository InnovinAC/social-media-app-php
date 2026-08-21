<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Phpvin\Application;
use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use Phpvin\Crypto\Encrypter;

/**
 * Generate an encryption key, and offer to write it into .env.
 *
 * The framework ships no default key on purpose. A key that comes with the
 * framework is a key every installation shares, which is the same as having
 * none at all.
 */
final class KeyGenerateCommand implements Command
{
    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Generate an application encryption key';
    }

    public function run(Input $input, Output $output): int
    {
        $key = 'base64:' . Encrypter::generateKey();

        if ($input->flag('show')) {
            $output->line($key);

            return 0;
        }

        $path = $input->option('env', $this->app->basePath('.env'));

        if (! is_file((string) $path)) {
            $output->warn('No .env at ' . $path);
            $output->line($key);
            $output->muted('Copy it in as APP_KEY, or run again with --env=<path>.');

            return 0;
        }

        $contents = (string) file_get_contents((string) $path);

        if (preg_match('/^APP_KEY=(.+)$/m', $contents, $matches) === 1 && trim($matches[1]) !== '') {
            // Replacing a live key locks everyone out of every sealed value,
            // so it takes more than a typo.
            if (! $input->flag('force')) {
                $output->error('APP_KEY is already set. Re-run with --force to replace it.');
                $output->muted('Every value sealed with the old key becomes unreadable.');

                return 1;
            }
        }

        $updated = preg_match('/^APP_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents)
            : rtrim($contents) . "\nAPP_KEY=" . $key . "\n";

        file_put_contents((string) $path, $updated);

        $output->success('APP_KEY written to ' . $path);

        return 0;
    }
}
