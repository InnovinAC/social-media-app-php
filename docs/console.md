# Console

[← back to the README](../README.md)

```bash
./phpvin                    # what is available
./phpvin migrate
./phpvin route:list --path=/admin
./phpvin make controller Post
./phpvin about
```

The entry point is four lines, and yours to edit:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap.php';

exit((new Phpvin\Console\Console($app))->run($argv));
```

Sharing `bootstrap.php` with the front controller is the point: a command runs
against the same container, the same providers and the same config as a request
does, so `migrate` cannot be looking at a different database than your app.

## What ships

| | |
| --- | --- |
| `about` | Versions, driver, debug state, where the log goes |
| `migrate` | Apply pending migrations; `--pending` lists without applying |
| `route:list` | Every route in matching order; `--path=` filters |
| `make` | `controller`, `model`, `middleware`, `migration` |
| `serve` | PHP's built-in server; `--host`, `--port`, `--root` |

`route:list` deliberately does not sort. The first match wins at runtime, so the
order shown is the order that matters.

`make` writes deliberately thin stubs. A generator that produces fifty lines you
did not ask for is one you end up deleting from, and the argument of this
framework is that you can read what you have. It never overwrites an existing
file.

## Writing a command

```php
final class PruneCommand implements Command
{
    public function __construct(private readonly Connection $db) {}

    public function name(): string { return 'prune'; }

    public function description(): string { return 'Delete expired sessions'; }

    public function run(Input $input, Output $output): int
    {
        $days = (int) $input->option('days', '30');

        if ($input->flag('dry-run')) {
            $output->warn("Would delete sessions older than $days days.");

            return 0;
        }

        $deleted = $this->db->statement('DELETE FROM sessions WHERE created_at < ?', [/* ... */]);

        $output->success("Deleted $deleted sessions.");

        return 0;
    }
}
```

```php
(new Console($app))->add(PruneCommand::class)->run($argv);
```

Constructor dependencies come from the container, exactly like a controller,
so a command that needs the database asks for a `Connection`. Class strings are
built lazily, only when the console actually runs, which is why an application
with no database configured can still run `make`.

## Input

Arguments are positional; options are `--name=value`; flags are `--name`. There
is no short-option guessing and no clustering.

```php
$input->argument(0);              $input->arguments();
$input->option('days', '30');     $input->flag('dry-run');
```

## Output

```php
$output->line('plain');       $output->success('done');
$output->warn('careful');     $output->error('failed');
$output->muted('detail');     $output->heading('Section');
$output->table(['Name', 'Value'], [['a', '1'], ['b', '2']]);
```

Colour is dropped when the stream is not a terminal, and when `NO_COLOR` is set
to anything at all. Table widths are measured without escape codes, so a
coloured cell still lines up.

Output goes to a stream rather than `echo`, which is what makes a command
testable:

```php
$stream = fopen('php://memory', 'r+');
$exit = (new Console($app))->run(['phpvin', 'route:list'], new Output($stream, decorated: false));

rewind($stream);
$this->assertStringContainsString('/posts', stream_get_contents($stream));
```

## Exit codes

`0` for success, anything else for failure, so `./phpvin migrate && ./phpvin
serve` behaves. An uncaught exception prints its message and returns `1`; add
`--verbose` for the stack trace.
