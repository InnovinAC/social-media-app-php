<?php

declare(strict_types=1);

namespace Phpvin\Asset;

use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Routing\Router;
use RuntimeException;

/**
 * Serves the JavaScript that ships with the framework.
 *
 * Register the route and the script is available with nothing to copy, no
 * build step and no publish command:
 *
 *     Assets::register($routes);
 *     <script src="{{ route('phpvin.script') }}"></script>
 *
 * For production, `publish()` copies the file into your own public directory
 * so the web server can serve it without PHP in the way.
 */
final class Assets
{
    public const SCRIPT_URI = '/_phpvin/phpvin.js';

    /**
     * Register the route that serves phpvin.js.
     */
    public static function register(Router $routes, string $uri = self::SCRIPT_URI): void
    {
        $routes->get(
            $uri,
            static fn (Request $request): Response => self::serve($request),
            as: 'phpvin.script',
        );
    }

    /**
     * Absolute path to the bundled phpvin.js.
     */
    public static function scriptPath(): string
    {
        $path = dirname(__DIR__, 2) . '/resources/js/phpvin.js';

        if (! is_file($path)) {
            throw new RuntimeException("The bundled phpvin.js is missing from [$path].");
        }

        return $path;
    }

    /**
     * Copy phpvin.js into an application's public directory.
     *
     * @return string The path written to.
     */
    public static function publish(string $targetDirectory): string
    {
        if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0o755, true) && ! is_dir($targetDirectory)) {
            throw new RuntimeException("Could not create [$targetDirectory].");
        }

        $target = rtrim($targetDirectory, '/') . '/phpvin.js';

        if (! copy(self::scriptPath(), $target)) {
            throw new RuntimeException("Could not copy phpvin.js into [$targetDirectory].");
        }

        return $target;
    }

    /**
     * Serve the script, answering 304 when the browser already has it.
     */
    public static function serve(Request $request): Response
    {
        $path = self::scriptPath();
        $etag = '"' . substr(hash_file('xxh128', $path), 0, 16) . '"';

        if ($request->header('if-none-match') === $etag) {
            return (new Response('', 304))->header('ETag', $etag);
        }

        return (new Response((string) file_get_contents($path)))
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('ETag', $etag)
            // Revalidate every time: the URL has no version in it, so a stale
            // copy after an upgrade would be silent and confusing.
            ->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }
}
