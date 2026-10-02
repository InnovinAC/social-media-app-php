<?php

declare(strict_types=1);

namespace Phpvin\Console;

use InvalidArgumentException;
use Phpvin\Application;
use Phpvin\Console\Commands\AboutCommand;
use Phpvin\Console\Commands\KeyGenerateCommand;
use Phpvin\Console\Commands\MakeCommand;
use Phpvin\Console\Commands\MigrateCommand;
use Phpvin\Console\Commands\RouteListCommand;
use Phpvin\Console\Commands\ServeCommand;
use Throwable;

/**
 * The command line entry point.
 *
 *     #!/usr/bin/env php
 *     <?php
 *     $app = require __DIR__ . '/bootstrap.php';
 *     exit((new Console($app))->run($argv));
 *
 * Commands are classes registered by name; there is no directory scanning and
 * no naming convention doing the work behind your back. `add()` takes a class
 * string and the container builds it, so a command can ask for a Connection
 * exactly like a controller can.
 */
final class Console
{
    /** Commands that ship with the framework. */
    private const DEFAULTS = [
        RouteListCommand::class,
        MigrateCommand::class,
        MakeCommand::class,
        KeyGenerateCommand::class,
        ServeCommand::class,
        AboutCommand::class,
    ];

    /** @var list<Command|class-string<Command>> */
    private array $registered = [];

    public function __construct(
        private readonly Application $app,
        private readonly bool $withDefaults = true,
    ) {}

    /**
     * Register a command.
     *
     * Class strings are kept as strings and only built when a command runs.
     * Building eagerly would mean `make:model` could not run in an application
     * with no database, because `migrate` needs one.
     *
     * @param Command|class-string<Command> $command
     */
    public function add(Command|string $command): self
    {
        $this->registered[] = $command;

        return $this;
    }

    /**
     * Build every command, indexed by name.
     *
     * A framework default that cannot be constructed is left out rather than
     * taking the whole console down: an application with no database has no
     * use for `migrate` and should still be able to run `make`. A command the
     * application registered itself is never swallowed.
     *
     * @return array<string, Command>
     */
    private function resolve(Output $output): array
    {
        $sources = $this->withDefaults
            ? [...array_map(static fn (string $c): array => [$c, true], self::DEFAULTS),
               ...array_map(static fn (Command|string $c): array => [$c, false], $this->registered)]
            : array_map(static fn (Command|string $c): array => [$c, false], $this->registered);

        $commands = [];

        foreach ($sources as [$source, $isDefault]) {
            try {
                $instance = is_string($source) ? $this->app->container()->get($source) : $source;
            } catch (Throwable $e) {
                if ($isDefault) {
                    continue;
                }

                throw $e;
            }

            if (! $instance instanceof Command) {
                throw new InvalidArgumentException(sprintf(
                    'A console command must implement %s, got %s.',
                    Command::class,
                    get_debug_type($instance),
                ));
            }

            $commands[$instance->name()] = $instance;
        }

        return $commands;
    }

    /**
     * @param  list<string> $argv
     * @return int          The exit code.
     */
    public function run(array $argv, ?Output $output = null): int
    {
        $output ??= new Output();
        $input = Input::fromArgv($argv);

        // Providers have to run before commands are built: `migrate` asks for
        // a Migrator that ActiveRecordProvider registers.
        $this->app->boot();

        $commands = $this->resolve($output);

        if ($input->command === '' || $input->command === 'list' || $input->flag('help')) {
            $this->listCommands($commands, $output);

            return 0;
        }

        $command = $commands[$input->command] ?? null;

        if ($command === null) {
            $output->error("There is no [{$input->command}] command.");
            $this->suggest($input->command, array_keys($commands), $output);

            return 1;
        }

        try {
            return $command->run($input, $output);
        } catch (Throwable $e) {
            // A stack trace is what you want from a CLI failure, but only when
            // you asked for one.
            $output->error($e->getMessage());

            if ($input->flag('verbose')) {
                $output->muted($e::class . ' at ' . $e->getFile() . ':' . $e->getLine());
                $output->muted($e->getTraceAsString());
            } else {
                $output->muted('Run again with --verbose for a stack trace.');
            }

            return 1;
        }
    }

    /**
     * @param array<string, Command> $commands
     */
    private function listCommands(array $commands, Output $output): void
    {
        $output->heading('phpvin ' . Application::VERSION);
        $output->line();

        $rows = [];
        ksort($commands);

        foreach ($commands as $name => $command) {
            $rows[] = [$output->paint($name, 'green'), $command->description()];
        }

        $output->table(['Command', 'Description'], $rows);
    }

    /**
     * Point at the nearest command rather than just refusing.
     */
    /**
     * @param list<string> $names
     */
    private function suggest(string $typed, array $names, Output $output): void
    {
        $best = null;
        $distance = PHP_INT_MAX;

        foreach ($names as $name) {
            $candidate = levenshtein($typed, $name);

            if ($candidate < $distance) {
                $distance = $candidate;
                $best = $name;
            }
        }

        if ($best !== null && $distance <= 3) {
            $output->line('Did you mean ' . $output->paint($best, 'green') . '?');
        }
    }
}
