<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Console\Command;
use Phpvin\Console\Console;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use RuntimeException;

final class ConsoleTest extends TestCase
{
    /** @var resource */
    private $stream;

    private Output $output;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'r+');
        // Colour off, so assertions match text rather than escape codes.
        $this->output = new Output($this->stream, decorated: false);
    }

    private function printed(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    private function app(array $config = []): Application
    {
        return new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'providers' => [],
            ...$config,
        ]);
    }

    /**
     * @param list<string> $arguments
     */
    private function execute(Console $console, array $arguments): int
    {
        return $console->run(['phpvin', ...$arguments], $this->output);
    }

    // --- input parsing --------------------------------------------------------

    #[Test]
    public function it_separates_the_command_arguments_options_and_flags(): void
    {
        $input = Input::fromArgv(['phpvin', 'make', 'controller', 'Post', '--force', '--path=app/Http']);

        $this->assertSame('make', $input->command);
        $this->assertSame(['controller', 'Post'], $input->arguments());
        $this->assertSame('controller', $input->argument(0));
        $this->assertSame('Post', $input->argument(1));
        $this->assertNull($input->argument(9));
        $this->assertSame('fallback', $input->argument(9, 'fallback'));
        $this->assertTrue($input->flag('force'));
        $this->assertFalse($input->flag('quiet'));
        $this->assertSame('app/Http', $input->option('path'));
        $this->assertSame('default', $input->option('missing', 'default'));
    }

    #[Test]
    public function an_empty_command_line_parses_to_nothing(): void
    {
        $input = Input::fromArgv(['phpvin']);

        $this->assertSame('', $input->command);
        $this->assertSame([], $input->arguments());
    }

    #[Test]
    public function a_flag_is_not_read_as_a_value(): void
    {
        $input = Input::fromArgv(['phpvin', 'migrate', '--pending']);

        $this->assertTrue($input->flag('pending'));
        $this->assertNull($input->option('pending'), 'a flag has no string value');
    }

    // --- output ----------------------------------------------------------------

    #[Test]
    public function colour_is_dropped_when_the_stream_is_not_a_terminal(): void
    {
        // Otherwise piping to a file fills it with escape codes. No explicit
        // flag here; this is the automatic detection doing the work.
        $automatic = new Output($this->stream);
        $automatic->success('done');

        $this->assertSame("done\n", $this->printed());
    }

    #[Test]
    public function colour_can_be_forced_on_and_off(): void
    {
        $on = new Output($this->stream, decorated: true);

        $this->assertStringContainsString("\033[32m", $on->paint('done', 'green'));
        $this->assertSame('done', (new Output($this->stream, decorated: false))->paint('done', 'green'));
    }

    #[Test]
    public function no_color_turns_colour_off_whatever_the_stream(): void
    {
        $previous = getenv('NO_COLOR');

        try {
            // The convention is that setting it at all is enough, whatever
            // the value.
            putenv('NO_COLOR=1');
            $this->assertTrue(Output::colourIsDisabled());
            $this->assertFalse(Output::detectDecoration(STDOUT));

            putenv('NO_COLOR=0');
            $this->assertTrue(Output::colourIsDisabled(), 'even a falsy value counts');

            putenv('NO_COLOR');
            $this->assertFalse(Output::colourIsDisabled());
            $this->assertSame(stream_isatty(STDOUT), Output::detectDecoration(STDOUT));
        } finally {
            $previous === false ? putenv('NO_COLOR') : putenv("NO_COLOR=$previous");
        }
    }

    #[Test]
    public function a_pipe_is_never_decorated(): void
    {
        $this->assertFalse(Output::detectDecoration($this->stream));
    }

    #[Test]
    public function an_unknown_colour_is_left_alone(): void
    {
        $this->assertSame('done', (new Output($this->stream, decorated: true))->paint('done', 'chartreuse'));
    }

    #[Test]
    public function a_table_lines_its_columns_up(): void
    {
        $this->output->table(['Name', 'Value'], [['short', '1'], ['a much longer name', '2']]);

        $lines = explode("\n", trim($this->printed()));

        $this->assertStringContainsString('Name', $lines[0]);
        // Both value columns start at the same offset.
        $this->assertSame(strpos($lines[1], '1'), strpos($lines[2], '2'));
    }

    #[Test]
    public function a_coloured_cell_still_lines_up(): void
    {
        $decorated = new Output($this->stream, decorated: true);
        $decorated->table(['A', 'B'], [[$decorated->paint('x', 'green'), 'first'], ['xxxxxx', 'second']]);

        $lines = explode("\n", trim($this->printed()));
        $stripped = array_map(fn (string $l): string => (string) preg_replace('/\033\[[0-9;]*m/', '', $l), $lines);

        // Width is measured without the escape codes, so the short painted
        // cell is padded to match the long plain one.
        $this->assertSame(strpos($stripped[1], 'first'), strpos($stripped[2], 'second'));
    }

    // --- dispatch ---------------------------------------------------------------

    #[Test]
    public function running_with_no_command_lists_what_is_available(): void
    {
        $exit = $this->execute(new Console($this->app()), []);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('make', $this->printed());
        $this->assertStringContainsString('route:list', $this->printed());
    }

    #[Test]
    public function an_unknown_command_fails_and_suggests_the_nearest(): void
    {
        $exit = $this->execute(new Console($this->app()), ['rout:lst']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no [rout:lst] command', $this->printed());
        $this->assertStringContainsString('Did you mean route:list', $this->printed());
    }

    #[Test]
    public function a_wildly_wrong_command_gets_no_suggestion(): void
    {
        $this->execute(new Console($this->app()), ['zzzzzzzzzzzzzz']);

        $this->assertStringNotContainsString('Did you mean', $this->printed());
    }

    #[Test]
    public function a_registered_command_runs_and_returns_its_exit_code(): void
    {
        $console = (new Console($this->app(), withDefaults: false))->add(new GreetCommand());

        $this->assertSame(0, $this->execute($console, ['greet', 'ada']));
        $this->assertStringContainsString('Hello, ada', $this->printed());
    }

    #[Test]
    public function a_failing_command_returns_its_own_code(): void
    {
        $console = (new Console($this->app(), withDefaults: false))->add(new GreetCommand());

        $this->assertSame(1, $this->execute($console, ['greet']), 'no name given');
    }

    #[Test]
    public function a_command_that_throws_reports_the_message_not_a_stack_trace(): void
    {
        $console = (new Console($this->app(), withDefaults: false))->add(new ExplodingCommand());

        $exit = $this->execute($console, ['explode']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('everything is on fire', $this->printed());
        $this->assertStringNotContainsString('#0', $this->printed(), 'no trace unless asked');
        $this->assertStringContainsString('--verbose', $this->printed());
    }

    #[Test]
    public function verbose_adds_the_stack_trace(): void
    {
        $console = (new Console($this->app(), withDefaults: false))->add(new ExplodingCommand());

        $this->execute($console, ['explode', '--verbose']);

        $this->assertStringContainsString('RuntimeException', $this->printed());
        $this->assertStringContainsString('#0', $this->printed());
    }

    #[Test]
    public function something_that_is_not_a_command_is_rejected(): void
    {
        $console = (new Console($this->app(), withDefaults: false))->add(PagesController::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $this->execute($console, ['anything']);
    }

    #[Test]
    public function list_and_help_reach_the_same_place_as_no_command(): void
    {
        foreach ([['list'], ['--help'], ['route:list', '--help']] as $arguments) {
            $stream = fopen('php://memory', 'r+');
            $output = new Output($stream, decorated: false);

            $this->assertSame(0, (new Console($this->app()))->run(['phpvin', ...$arguments], $output));

            rewind($stream);
            $this->assertStringContainsString('Description', (string) stream_get_contents($stream));
        }
    }

    #[Test]
    public function a_near_miss_is_suggested_but_a_distant_one_is_not(): void
    {
        // 'abou' is one edit from 'about'; 'aaaaaa' is far from everything.
        $this->execute(new Console($this->app()), ['abou']);
        $this->assertStringContainsString('Did you mean about', $this->printed());
    }

    #[Test]
    public function a_typo_exactly_at_the_threshold_is_still_suggested(): void
    {
        // Three edits from 'serve': the last distance that should help.
        $this->execute(new Console($this->app()), ['serxyz']);

        $this->assertStringContainsString('Did you mean serve', $this->printed());
    }

    #[Test]
    public function a_typo_beyond_the_threshold_gets_no_suggestion(): void
    {
        $this->execute(new Console($this->app()), ['xxxxxxxxx']);

        $this->assertStringNotContainsString('Did you mean', $this->printed());
    }

    #[Test]
    public function a_broken_command_registered_alongside_the_defaults_is_not_swallowed(): void
    {
        // The defaults are skipped when they cannot be built; an application's
        // own command is a mistake worth surfacing either way.
        $console = (new Console($this->app(), withDefaults: true))->add(NeedsSomethingMissing::class);

        $this->expectException(\Phpvin\Container\ContainerException::class);

        $this->execute($console, ['anything']);
    }

    #[Test]
    public function a_registered_command_that_cannot_be_built_is_not_swallowed(): void
    {
        // Framework defaults are skipped when they cannot be constructed. A
        // command the application registered is a mistake worth surfacing.
        $console = (new Console($this->app(), withDefaults: false))->add(NeedsSomethingMissing::class);

        $this->expectException(\Phpvin\Container\ContainerException::class);

        $this->execute($console, ['anything']);
    }

    // --- lazy construction -------------------------------------------------------

    #[Test]
    public function commands_are_built_only_when_the_console_runs(): void
    {
        CountingCommand::$built = 0;

        new Console($this->app(), withDefaults: false);

        $this->assertSame(0, CountingCommand::$built, 'constructing the console builds nothing');
    }

    #[Test]
    public function a_database_less_application_can_still_use_the_console(): void
    {
        // `migrate` needs a Connection that does not exist here. Building
        // every default eagerly would take the whole console down with it.
        $exit = $this->execute(new Console($this->app(['database' => null])), []);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('make', $this->printed());
        $this->assertStringNotContainsString('migrate', $this->printed(), 'omitted, not fatal');
    }

    #[Test]
    public function a_database_backed_application_gets_the_migrate_command(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'database.migrations' => __DIR__ . '/fixtures',
        ]);

        $this->execute(new Console($app), []);

        $this->assertStringContainsString('migrate', $this->printed());

        \Phpvin\Database\Model::useConnection(null);
    }

    #[Test]
    public function route_list_shows_the_registered_routes(): void
    {
        $app = $this->app();
        $app->router()->get('/posts/{id}', [PagesController::class, 'show'], as: 'posts.show');

        $this->execute(new Console($app), ['route:list']);

        $printed = $this->printed();

        $this->assertStringContainsString('/posts/{id}', $printed);
        $this->assertStringContainsString('posts.show', $printed);
        $this->assertStringContainsString('PagesController@show', $printed);
        $this->assertStringContainsString('1 route', $printed);
    }

    #[Test]
    public function route_list_can_be_filtered(): void
    {
        $app = $this->app();
        $app->router()->get('/posts', [PagesController::class, 'index']);
        $app->router()->get('/users', [PagesController::class, 'index']);

        $this->execute(new Console($app), ['route:list', '--path=posts']);

        $this->assertStringContainsString('/posts', $this->printed());
        $this->assertStringNotContainsString('/users', $this->printed());
    }

    #[Test]
    public function route_list_says_so_when_there_are_none(): void
    {
        $this->execute(new Console($this->app()), ['route:list']);

        $this->assertStringContainsString('No routes are registered', $this->printed());
    }

    #[Test]
    public function about_reports_the_configuration_in_effect(): void
    {
        $this->execute(new Console($this->app(['debug' => true])), ['about']);

        $printed = $this->printed();

        $this->assertStringContainsString('phpvin', $printed);
        $this->assertStringContainsString(PHP_VERSION, $printed);
        $this->assertStringContainsString('never in production', $printed, 'debug is called out');
        $this->assertStringContainsString('not configured', $printed, 'and so is a missing log');
        $this->assertStringContainsString('none configured', $printed, 'and a missing database');
    }

    #[Test]
    public function about_reports_a_configured_database(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $this->execute(new Console($app), ['about']);

        $this->assertStringContainsString('sqlite (connected)', $this->printed());

        \Phpvin\Database\Model::useConnection(null);
    }

    #[Test]
    public function about_reports_a_database_it_cannot_reach(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'database' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'nope'],
        ]);

        $this->execute(new Console($app), ['about']);

        // Saying which driver and why beats a bare stack trace when the answer
        // is "the database is not running".
        $this->assertStringContainsString('mysql:', $this->printed());

        \Phpvin\Database\Model::useConnection(null);
    }
}

final class NeedsSomethingMissing implements Command
{
    public function __construct(public string $missing) {}

    public function name(): string
    {
        return 'needs';
    }

    public function description(): string
    {
        return 'Cannot be built';
    }

    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}

final class GreetCommand implements Command
{
    public function name(): string
    {
        return 'greet';
    }

    public function description(): string
    {
        return 'Say hello';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0);

        if ($name === null) {
            $output->error('Who?');

            return 1;
        }

        $output->success("Hello, $name");

        return 0;
    }
}

final class ExplodingCommand implements Command
{
    public function name(): string
    {
        return 'explode';
    }

    public function description(): string
    {
        return 'Throws';
    }

    public function run(Input $input, Output $output): int
    {
        throw new RuntimeException('everything is on fire');
    }
}

final class CountingCommand implements Command
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function name(): string
    {
        return 'counting';
    }

    public function description(): string
    {
        return 'Counts how often it was built';
    }

    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
