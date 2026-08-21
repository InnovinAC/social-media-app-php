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

    /** @var array{0: int, 1: int|null}|null */
    private ?array $range = null;

    /** False until computed, then the leading literal segment or null. */
    private string|false|null $leading = false;

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

    /**
     * How many path segments this route can match.
     *
     * Used to bucket routes so a request only ever tries the ones that could
     * plausibly match its shape.
     *
     * @return array{0: int, 1: int|null} Minimum, and maximum or null for
     *                                    unbounded (a catch-all).
     */
    public function segmentRange(): array
    {
        if ($this->range !== null) {
            return $this->range;
        }

        $minimum = 0;
        $maximum = 0;

        foreach (explode('/', trim($this->uri, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{(\w+)([?*])?\}$/', $segment, $m) !== 1) {
                $minimum++;
                $maximum++;

                continue;
            }

            $modifier = $m[2] ?? '';

            if ($modifier === '*') {
                // A catch-all swallows the rest of the path, however long.
                return $this->range = [$minimum, null];
            }

            if ($modifier === '?') {
                $maximum++;

                continue;
            }

            $minimum++;
            $maximum++;
        }

        return $this->range = [$minimum, $maximum];
    }

    /**
     * The first path segment, when it is a literal rather than a placeholder.
     *
     * `/users/{id}` answers "users"; `/{tenant}/settings` answers null. Used
     * to narrow the candidates for a request before any regex runs. Most
     * route tables are a handful of literal prefixes with placeholders behind
     * them, so this is where the discrimination is.
     */
    public function leadingSegment(): ?string
    {
        if ($this->leading !== false) {
            return $this->leading;
        }

        $first = explode('/', trim($this->uri, '/'))[0] ?? '';

        // The empty case is only reachable for '/', which is always static
        // and never asked for its leading segment.
        return $this->leading = ($first === '' || str_contains($first, '{')) ? null : $first; // mutation:ignore
    }

    /** Whether the URI contains any placeholder at all. */
    public function isStatic(): bool
    {
        return ! str_contains($this->uri, '{');
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
