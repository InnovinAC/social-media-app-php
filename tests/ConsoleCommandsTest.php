<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Console\Commands\KeyGenerateCommand;
use Phpvin\Console\Commands\MakeCommand;
use Phpvin\Console\Commands\MigrateCommand;
use Phpvin\Console\Commands\RouteListCommand;
use Phpvin\Console\Commands\ServeCommand;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use Phpvin\Crypto\Encrypter;
use Phpvin\Database\Connection;
use Phpvin\Database\Migrator;
use Phpvin\Database\Model;

/**
 * The shipped commands, run against a throwaway application directory.
 */
final class ConsoleCommandsTest extends TestCase
{
    private string $base;

    /** @var resource */
    private $stream;

    private Output $output;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/phpvin-console-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/database/migrations', 0o755, true);

        $this->stream = fopen('php://memory', 'r+');
        $this->output = new Output($this->stream, decorated: false);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->base);
        Model::useConnection(null);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = "$path/$entry";
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }

    private function printed(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    private function app(): Application
    {
        return new Application($this->base, [
            'views' => ['engine' => 'none'],
            'session' => false,
            'providers' => [],
        ]);
    }

    /**
     * @param list<string> $argv
     */
    private function input(array $argv): Input
    {
        return Input::fromArgv(['phpvin', ...$argv]);
    }

    // --- make ------------------------------------------------------------------

    #[Test]
    public function it_generates_a_controller_that_parses(): void
    {
        $command = new MakeCommand($this->app());

        $this->assertSame(0, $command->run($this->input(['make', 'controller', 'Post']), $this->output));

        $path = $this->base . '/app/Controllers/PostController.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString('final class PostController', (string) file_get_contents($path));
        $this->assertNull($this->parseError($path), 'generated code must be valid PHP');
    }

    #[Test]
    public function the_controller_suffix_is_added_once_and_only_once(): void
    {
        $command = new MakeCommand($this->app());

        $command->run($this->input(['make', 'controller', 'Post']), $this->output);
        $command->run($this->input(['make', 'controller', 'UserController']), $this->output);

        $this->assertFileExists($this->base . '/app/Controllers/PostController.php');
        $this->assertFileExists($this->base . '/app/Controllers/UserController.php');
        $this->assertFileDoesNotExist($this->base . '/app/Controllers/UserControllerController.php');
    }

    #[Test]
    public function a_dashed_name_becomes_a_class_name(): void
    {
        (new MakeCommand($this->app()))->run($this->input(['make', 'model', 'blog-post']), $this->output);

        $this->assertFileExists($this->base . '/app/Models/BlogPost.php');
    }

    #[Test]
    public function it_generates_a_model_and_a_middleware(): void
    {
        $command = new MakeCommand($this->app());

        $command->run($this->input(['make', 'model', 'Post']), $this->output);
        $command->run($this->input(['make', 'middleware', 'RequireAdmin']), $this->output);

        $this->assertNull($this->parseError($this->base . '/app/Models/Post.php'));
        $this->assertNull($this->parseError($this->base . '/app/Middleware/RequireAdmin.php'));
    }

    #[Test]
    public function migrations_are_numbered_in_sequence(): void
    {
        $command = new MakeCommand($this->app());

        $command->run($this->input(['make', 'migration', 'create_posts_table']), $this->output);
        $command->run($this->input(['make', 'migration', 'add index to posts']), $this->output);

        $this->assertFileExists($this->base . '/database/migrations/001_create_posts_table.php');
        $this->assertFileExists($this->base . '/database/migrations/002_add_index_to_posts.php');
    }

    #[Test]
    public function an_existing_file_is_never_overwritten(): void
    {
        $command = new MakeCommand($this->app());
        $command->run($this->input(['make', 'model', 'Post']), $this->output);

        file_put_contents($this->base . '/app/Models/Post.php', '<?php // my work');

        $exit = $command->run($this->input(['make', 'model', 'Post']), $this->output);

        $this->assertSame(1, $exit);
        $this->assertSame('<?php // my work', file_get_contents($this->base . '/app/Models/Post.php'));
        $this->assertStringContainsString('Already exists', $this->printed());
    }

    #[Test]
    public function an_unknown_type_is_refused_with_the_usage(): void
    {
        $exit = (new MakeCommand($this->app()))->run($this->input(['make', 'sandwich', 'Cheese']), $this->output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage: make', $this->printed());
    }

    #[Test]
    public function a_missing_name_is_refused(): void
    {
        $exit = (new MakeCommand($this->app()))->run($this->input(['make', 'model']), $this->output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Usage: make', $this->printed());
    }

    // --- migrate ----------------------------------------------------------------

    private function migrateCommand(Connection $connection): MigrateCommand
    {
        return new MigrateCommand(new Migrator($connection, $this->base . '/database/migrations'), $connection);
    }

    private function writeMigration(string $name, string $body): void
    {
        file_put_contents(
            $this->base . "/database/migrations/$name.php",
            "<?php use Phpvin\\Database\\Connection; return function (Connection \$db): void { $body };",
        );
    }

    #[Test]
    public function migrate_says_when_there_is_nothing_to_do(): void
    {
        $exit = $this->migrateCommand(Connection::sqliteInMemory())->run($this->input(['migrate']), $this->output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to migrate', $this->printed());
    }

    #[Test]
    public function migrate_pending_says_when_there_is_nothing_pending(): void
    {
        $exit = $this->migrateCommand(Connection::sqliteInMemory())
            ->run($this->input(['migrate', '--pending']), $this->output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing pending', $this->printed());
    }

    #[Test]
    public function migrate_pending_lists_without_applying(): void
    {
        $connection = Connection::sqliteInMemory();
        $this->writeMigration('001_first', '$db->statement("CREATE TABLE a (id INTEGER)");');

        $this->migrateCommand($connection)->run($this->input(['migrate', '--pending']), $this->output);

        $this->assertStringContainsString('1 pending', $this->printed());
        $this->assertStringContainsString('001_first', $this->printed());
        $this->assertSame([], $this->tables($connection), 'nothing was actually applied');
    }

    #[Test]
    public function migrate_applies_and_counts_in_the_singular(): void
    {
        $connection = Connection::sqliteInMemory();
        $this->writeMigration('001_first', '$db->statement("CREATE TABLE a (id INTEGER)");');

        $this->migrateCommand($connection)->run($this->input(['migrate']), $this->output);

        $this->assertStringContainsString('migrated  001_first', $this->printed());
        $this->assertStringContainsString('1 migration applied', $this->printed());
        $this->assertStringNotContainsString('1 migrations', $this->printed());
    }

    #[Test]
    public function migrate_counts_in_the_plural_too(): void
    {
        $connection = Connection::sqliteInMemory();
        $this->writeMigration('001_first', '$db->statement("CREATE TABLE a (id INTEGER)");');
        $this->writeMigration('002_second', '$db->statement("CREATE TABLE b (id INTEGER)");');

        $this->migrateCommand($connection)->run($this->input(['migrate']), $this->output);

        $this->assertStringContainsString('2 migrations applied', $this->printed());
    }

    #[Test]
    public function migrate_warns_when_the_driver_cannot_roll_back_ddl(): void
    {
        if (DatabaseTestCase::driver() !== 'mysql') {
            $this->markTestSkipped('Only MySQL commits implicitly on DDL.');
        }

        $connection = DatabaseTestCase::newConnection();
        $this->writeMigration('001_first', '$db->statement("CREATE TABLE console_probe (id INT)");');

        $this->migrateCommand($connection)->run($this->input(['migrate']), $this->output);

        $this->assertStringContainsString('cannot roll back DDL', $this->printed());

        $connection->statement('DROP TABLE IF EXISTS console_probe');
        $connection->statement('DROP TABLE IF EXISTS migrations');
    }

    // --- route:list ----------------------------------------------------------------

    #[Test]
    public function route_list_counts_a_single_route_in_the_singular(): void
    {
        $app = $this->app();
        $app->router()->get('/only', [PagesController::class, 'index']);

        (new RouteListCommand($app->router()))->run($this->input(['route:list']), $this->output);

        $this->assertStringContainsString('1 route', $this->printed());
        $this->assertStringNotContainsString('1 routes', $this->printed());
    }

    #[Test]
    public function route_list_shows_middleware_when_there_is_some(): void
    {
        $app = $this->app();
        $app->router()->get('/plain', [PagesController::class, 'index']);
        $app->router()->get('/guarded', [PagesController::class, 'index'], through: [RecordingMiddleware::class]);

        (new RouteListCommand($app->router()))->run($this->input(['route:list']), $this->output);

        $printed = $this->printed();

        $this->assertStringContainsString('RecordingMiddleware', $printed);
        $this->assertStringContainsString('2 routes', $printed);
        $this->assertMatchesRegularExpression('~/plain\s.*\s-$~m', $printed, 'and a dash where there is none');
    }

    #[Test]
    public function route_list_names_a_closure_handler_as_such(): void
    {
        $app = $this->app();
        $app->router()->get('/closure', fn (): string => 'x');

        (new RouteListCommand($app->router()))->run($this->input(['route:list']), $this->output);

        $this->assertStringContainsString('Closure', $this->printed());
    }

    // --- key:generate -----------------------------------------------------------

    #[Test]
    public function key_generate_show_prints_a_usable_key(): void
    {
        $exit = (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate', '--show']), $this->output);

        $key = trim($this->printed());

        $this->assertSame(0, $exit);
        $this->assertStringStartsWith('base64:', $key);

        // Not just well-formed: it has to actually work.
        $encrypter = Encrypter::fromKey($key);
        $this->assertSame('round trip', $encrypter->decrypt($encrypter->encrypt('round trip')));
    }

    #[Test]
    public function key_generate_writes_into_an_env_file(): void
    {
        $env = $this->base . '/.env';
        file_put_contents($env, "APP_DEBUG=true\nAPP_KEY=\n");

        $exit = (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate']), $this->output);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S+$/m', (string) file_get_contents($env));
        $this->assertStringContainsString('APP_DEBUG=true', (string) file_get_contents($env), 'the rest is untouched');
    }

    #[Test]
    public function key_generate_appends_when_there_is_no_app_key_line(): void
    {
        $env = $this->base . '/.env';
        file_put_contents($env, "APP_DEBUG=true\n");

        (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate']), $this->output);

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S+$/m', (string) file_get_contents($env));
    }

    #[Test]
    public function key_generate_refuses_to_replace_a_live_key(): void
    {
        $env = $this->base . '/.env';
        file_put_contents($env, "APP_KEY=base64:existingkeyvalue\n");

        $exit = (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate']), $this->output);

        // Replacing it makes every sealed value unreadable, so it needs more
        // than a stray keystroke.
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already set', $this->printed());
        $this->assertStringContainsString('base64:existingkeyvalue', (string) file_get_contents($env));
    }

    #[Test]
    public function key_generate_replaces_a_live_key_when_forced(): void
    {
        $env = $this->base . '/.env';
        file_put_contents($env, "APP_KEY=base64:existingkeyvalue\n");

        $exit = (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate', '--force']), $this->output);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('existingkeyvalue', (string) file_get_contents($env));
    }

    #[Test]
    public function key_generate_prints_the_key_when_there_is_no_env_file(): void
    {
        $exit = (new KeyGenerateCommand($this->app()))->run($this->input(['key:generate']), $this->output);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No .env at', $this->printed());
        $this->assertStringContainsString('base64:', $this->printed());
    }

    // --- serve --------------------------------------------------------------------

    #[Test]
    public function serve_refuses_when_there_is_no_public_directory(): void
    {
        $exit = (new ServeCommand($this->app()))->run($this->input(['serve']), $this->output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no public directory', $this->printed());
    }

    /** @return list<string> */
    private function tables(Connection $connection): array
    {
        $rows = $connection->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

        return array_values(array_filter(
            array_column($rows, 'name'),
            static fn (string $name): bool => $name !== 'migrations',
        ));
    }

    private function parseError(string $path): ?string
    {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $exit);

        return $exit === 0 ? null : implode("\n", $out);
    }
}
