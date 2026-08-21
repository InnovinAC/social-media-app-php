<?php

declare(strict_types=1);

namespace Phpvin\Routing;

use Closure;
use InvalidArgumentException;

/**
 * A single registered route.
 *
 * Handlers are always `[SomeController::class, 'method']` or a closure. There
 * is deliberately no `'SomeController@method'` string form: a class constant
 * is navigable in an IDE, survives a rename, and fails at parse time rather
 * than at request time.
 *
 * Placeholder syntax:
 *   {id}      required, matches a single segment
 *   {slug?}   optional, matches a single segment
 *   {path*}   catch-all, matches the rest of the path including slashes
 */
final class Route
{
    private ?string $compiled = null;

    /**
     * @param list<string>                            $methods
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                   $wheres
     * @param list<class-string|Closure>              $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $uri,
        public readonly array|Closure $handler,
        public readonly ?string $name = null,
        public readonly array $wheres = [],
        public readonly array $middleware = [],
    ) {
        if (is_array($handler) && ! (count($handler) === 2 && is_string($handler[0]) && is_string($handler[1]))) {
            throw new InvalidArgumentException(
                'A route handler array must be [ControllerClass::class, \'method\'].',
            );
        }
    }

    /**
     * Try this route against a path.
     *
     * @return array<string, string>|null Extracted parameters, or null if the
     *                                    path does not match.
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->pattern(), $path, $matches) !== 1) {
            return null;
        }

        $parameters = array_filter(
            $matches,
            static fn (string|int $key): bool => is_string($key),
            ARRAY_FILTER_USE_KEY,
        );

        // Decode only after matching. Decoding first would turn an encoded
        // %2F into a real slash and change which segments exist.
        return array_map(rawurldecode(...), $parameters);
    }

    public function acceptsMethod(string $method): bool
    {
        $method = strtoupper($method);

        // A HEAD request is a GET whose body the server throws away.
        if ($method === 'HEAD' && in_array('GET', $this->methods, true)) { // mutation:ignore strict flag is equivalent for a list of verb strings
            return true;
        }

        return in_array($method, $this->methods, true); // mutation:ignore strict flag is equivalent for a list of verb strings
    }

    /**
     * Build a concrete path from this route's placeholders.
     *
     * @param array<string, string|int> $parameters
     */
    public function toPath(array $parameters = []): string
    {
        $path = preg_replace_callback(
            '/\{(\w+)([?*])?\}/',
            static function (array $m) use ($parameters, &$missing): string {
                $name = $m[1];
                $optional = isset($m[2]);

                if (isset($parameters[$name])) {
                    return (string) $parameters[$name];
                }

                if (! $optional) {
                    $missing = $name;
                }

                return '';
            },
            $this->uri,
        ) ?? $this->uri;

        if (isset($missing)) {
            throw new InvalidArgumentException(
                "Missing parameter [$missing] for route [{$this->uri}].",
            );
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function pattern(): string
    {
        return $this->compiled ??= $this->compile();
    }

    private function compile(): string
    {
        $pattern = '';

        foreach (explode('/', trim($this->uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{(\w+)([?*])?\}$/', $segment, $m) !== 1) {
                $pattern .= '/' . preg_quote($segment, '#');

                continue;
            }

            [$name, $modifier] = [$m[1], $m[2] ?? ''];

            $constraint = $this->wheres[$name] ?? ($modifier === '*' ? '.*' : '[^/]+');
            $group = "(?P<$name>$constraint)";

            $pattern .= $modifier === '' ? "/$group" : "(?:/$group)?";
        }

        return '#^' . ($pattern === '' ? '/' : $pattern) . '$#';
    }
}
