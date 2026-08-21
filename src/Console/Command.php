<?php

declare(strict_types=1);

namespace Phpvin\Console;

/**
 * A console command.
 *
 * Constructor dependencies are resolved from the container, same as a
 * controller, so a command that needs the database asks for a Connection.
 */
interface Command
{
    /** The name typed on the command line, e.g. `route:list`. */
    public function name(): string;

    /** One line, shown in the command list. */
    public function description(): string;

    /** @return int Exit code: 0 for success. */
    public function run(Input $input, Output $output): int;
}
