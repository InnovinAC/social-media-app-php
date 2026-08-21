<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Phpvin\Application;
use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;

/**
 * Generate a file from a stub.
 *
 *     phpvin make controller PostController
 *     phpvin make model Post
 *     phpvin make middleware RequireAdmin
 *     phpvin make migration create_posts_table
 *
 * The stubs are deliberately thin. A generator that writes fifty lines you did
 * not ask for is a generator you end up deleting from, and this framework's
 * whole argument is that you can read what you have.
 */
final class MakeCommand implements Command
{
    private const TYPES = ['controller', 'model', 'middleware', 'migration'];

    public function __construct(private readonly Application $app) {}

    public function name(): string
    {
        return 'make';
    }

    public function description(): string
    {
        return 'Generate a controller, model, middleware or migration';
    }

    public function run(Input $input, Output $output): int
    {
        $type = strtolower((string) $input->argument(0, ''));
        $name = (string) $input->argument(1, '');

        if (! in_array($type, self::TYPES, true) || $name === '') { // mutation:ignore strict flag is equivalent for an array of string literals
            $output->error('Usage: make <' . implode('|', self::TYPES) . '> <Name>');

            return 1;
        }

        [$path, $contents] = match ($type) {
            'controller' => $this->controller($name),
            'model' => $this->model($name),
            'middleware' => $this->middleware($name),
            'migration' => $this->migration($name, $input),
        };

        if (file_exists($path)) {
            // Never silently: a generator that overwrites your work once is a
            // generator you never trust again.
            $output->error('Already exists: ' . $this->relative($path));

            return 1;
        }

        $directory = dirname($path);

        // Trailing is_dir() covers a concurrent create; unreachable by test.
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) { // mutation:ignore race guard
            $output->error("Could not create [$directory].");

            return 1;
        }

        file_put_contents($path, $contents);
        $output->success('Created ' . $this->relative($path));

        return 0;
    }

    /** @return array{0: string, 1: string} */
    private function controller(string $name): array
    {
        $class = $this->className($name, 'Controller');

        return [$this->app->basePath("app/Controllers/$class.php"), <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Phpvin\Http\Request;
            use Phpvin\Http\Response;
            use Phpvin\View\ViewFactory;

            final class $class
            {
                public function __construct(private readonly ViewFactory \$views) {}

                public function index(Request \$request): Response
                {
                    return \$this->views->response('welcome');
                }
            }

            PHP];
    }

    /** @return array{0: string, 1: string} */
    private function model(string $name): array
    {
        $class = $this->className($name);

        return [$this->app->basePath("app/Models/$class.php"), <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Models;

            use Phpvin\Database\Model;

            final class $class extends Model
            {
                /**
                 * Columns that may be set from request data. Empty means none,
                 * so nothing reaches the database that you did not list.
                 *
                 * @var list<string>
                 */
                protected static array \$fillable = [];

                /** @var array<string, string> */
                protected static array \$casts = [];
            }

            PHP];
    }

    /** @return array{0: string, 1: string} */
    private function middleware(string $name): array
    {
        $class = $this->className($name);

        return [$this->app->basePath("app/Middleware/$class.php"), <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\Middleware;

            use Closure;
            use Phpvin\Http\Request;
            use Phpvin\Http\Response;
            use Phpvin\Middleware\Middleware;

            final class $class implements Middleware
            {
                public function process(Request \$request, Closure \$next): Response
                {
                    // Work before the handler goes here.

                    \$response = \$next(\$request);

                    // Work after it goes here.

                    return \$response;
                }
            }

            PHP];
    }

    /** @return array{0: string, 1: string} */
    private function migration(string $name, Input $input): array
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $directory = (string) $this->app->config('database.migrations', $this->app->basePath('database/migrations'));

        // Numbered, not timestamped: migrations run in filename order, and a
        // three-digit prefix is easier to reason about in a small project.
        $existing = glob(rtrim($directory, '/') . '/*.php') ?: [];
        $next = str_pad((string) (count($existing) + 1), 3, '0', STR_PAD_LEFT);

        return [rtrim($directory, '/') . "/{$next}_{$slug}.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            use Phpvin\Database\Connection;

            return function (Connection $db): void {
                $autoIncrement = $db->driver() === 'sqlite'
                    ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
                    : ($db->driver() === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY');

                $db->statement(
                    "CREATE TABLE example (
                        id $autoIncrement,
                        created_at VARCHAR(32) NULL,
                        updated_at VARCHAR(32) NULL
                    )"
                );
            };

            PHP];
    }

    private function className(string $name, string $suffix = ''): string
    {
        $class = str_replace(['-', '_', ' '], '', ucwords($name, '-_ '));

        return $suffix !== '' && ! str_ends_with($class, $suffix) ? $class . $suffix : $class;
    }

    private function relative(string $path): string
    {
        return str_replace($this->app->basePath() . '/', '', $path);
    }
}
