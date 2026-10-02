<?php

declare(strict_types=1);

namespace Phpvin\Routing;

use Closure;
use LogicException;
use Phpvin\Http\HttpException;
use Phpvin\Http\Request;

/**
 * Route registration and matching.
 *
 * Routes are configured with named arguments rather than a fluent chain. One
 * call is one route, all of its configuration visible in one place:
 *
 *     $routes->get('/posts/{id}', [PostController::class, 'show'],
 *         as: 'posts.show',
 *         where: ['id' => '\d+'],
 *         through: [RequireUser::class],
 *     );
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * Static paths, by method then path. A hash lookup rather than a scan.
     *
     * @var array<string, array<string, int>> method => path => registration index
     */
    private array $static = [];

    /**
     * Routes with placeholders, bucketed by how many segments they can match
     * and by their leading literal segment.
     *
     * A two-segment request never tries a five-segment route, and a request
     * for /users/7 never tries /posts/{id}. Routes whose first segment is
     * itself a placeholder go under '*' and are always tried.
     *
     * @var array<string, array<int, array<string, list<int>>>>
     */
    private array $dynamic = [];

    /**
     * Catch-all routes, which match any number of segments. Bucketed by
     * leading segment for the same reason.
     *
     * @var array<string, array<string, list<int>>>
     */
    private array $unbounded = [];


    /** @var array<string, Route> */
    private array $named = [];

    private string $prefix = '';

    private string $namePrefix = '';

    /** @var list<class-string|Closure> */
    private array $groupMiddleware = [];

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function get(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['GET'], $uri, $handler, $as, $where, $through);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function post(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['POST'], $uri, $handler, $as, $where, $through);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function put(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['PUT'], $uri, $handler, $as, $where, $through);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function patch(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['PATCH'], $uri, $handler, $as, $where, $through);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function delete(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['DELETE'], $uri, $handler, $as, $where, $through);
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function options(string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        return $this->on(['OPTIONS'], $uri, $handler, $as, $where, $through);
    }

    /**
     * Register one handler against several verbs.
     *
     * @param list<string>                              $methods
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, string>                     $where
     * @param list<class-string|Closure>                $through
     */
    public function on(array $methods, string $uri, array|Closure $handler, ?string $as = null, array $where = [], array $through = []): Route
    {
        $name = $as === null ? null : $this->namePrefix . $as;

        $route = new Route(
            methods: array_map(strtoupper(...), $methods),
            uri: $this->join($this->prefix, $uri),
            handler: $handler,
            name: $name,
            wheres: $where,
            middleware: [...$this->groupMiddleware, ...$through],
        );

        $index = count($this->routes);
        $this->routes[] = $route;
        $this->index($route, $index);

        if ($name !== null) {
            if (isset($this->named[$name])) {
                throw new LogicException("Two routes are both named [$name].");
            }

            $this->named[$name] = $route;
        }

        return $route;
    }

    /**
     * Register a batch of routes sharing a prefix, middleware stack, or name
     * prefix. Groups nest.
     *
     *     $routes->group(prefix: '/admin', through: [RequireAdmin::class], as: 'admin.',
     *         define: function (Router $routes) {
     *             $routes->get('/users', [UserController::class, 'index'], as: 'users');
     *         },
     *     );
     *
     * @param list<class-string|Closure> $through
     * @param Closure(Router): void      $define
     */
    public function group(Closure $define, string $prefix = '', array $through = [], string $as = ''): void
    {
        $previous = [$this->prefix, $this->groupMiddleware, $this->namePrefix];

        $this->prefix = $this->join($this->prefix, $prefix);
        $this->groupMiddleware = [...$this->groupMiddleware, ...$through];
        $this->namePrefix .= $as;

        try {
            $define($this);
        } finally {
            [$this->prefix, $this->groupMiddleware, $this->namePrefix] = $previous;
        }
    }

    /**
     * Find the route for a request.
     *
     * @throws HttpException 404 when no route matches the path,
     *                       405 when the path matches but the verb does not
     */
    public function resolve(Request $request): RouteMatch
    {
        $match = $this->find($request->method, $request->path);

        if ($match !== null) {
            return $match;
        }

        // Nothing matched for this verb. Before answering 404, find out
        // whether the path exists under another one; that is a 405, and the
        // difference matters to anything reading the response.
        $allowed = $this->methodsFor($request->path, $request->method);

        if ($allowed !== []) {
            throw new HttpException(405, sprintf(
                'The %s route only accepts %s.',
                $request->path,
                implode(', ', $allowed),
            ));
        }

        throw HttpException::notFound("No route matches {$request->method} {$request->path}.");
    }

    /**
     * The first route that matches, in registration order.
     *
     * Order is the contract: the first route registered wins, and bucketing
     * must not quietly change that. So the static hit and the dynamic
     * candidates are compared by registration index rather than by which
     * lookup happened to run first.
     */
    private function find(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);

        $candidate = $this->findFor($method, $path);

        // A HEAD request is served by the matching GET route.
        if ($candidate === null && $method === 'HEAD') {
            $candidate = $this->findFor('GET', $path);
        }

        return $candidate;
    }

    private function findFor(string $method, string $path): ?RouteMatch
    {
        $staticIndex = $this->static[$method][$path] ?? PHP_INT_MAX;

        // Only dynamic routes registered *before* the static hit can beat it,
        // so a static match usually ends the search immediately.
        foreach ($this->dynamicCandidates($method, $path) as $index) {
            if ($index > $staticIndex) {
                break;
            }

            $parameters = $this->routes[$index]->match($path);

            if ($parameters !== null) {
                return new RouteMatch($this->routes[$index], $parameters);
            }
        }

        return $staticIndex === PHP_INT_MAX
            ? null
            : new RouteMatch($this->routes[$staticIndex], []);
    }

    /**
     * Dynamic route indexes that could match this path, in registration order.
     *
     * @return list<int>
     */
    private function dynamicCandidates(string $method, string $path): array
    {
        $segments = $path === '/' ? 0 : substr_count($path, '/');
        $leading = explode('/', trim($path, '/'))[0] ?? '';

        $lists = [
            $this->dynamic[$method][$segments][$leading] ?? [],
            $this->dynamic[$method][$segments]['*'] ?? [],
            $this->unbounded[$method][$leading] ?? [],
            $this->unbounded[$method]['*'] ?? [],
        ];

        $lists = array_values(array_filter($lists));

        if ($lists === []) {
            return [];
        }

        if (count($lists) === 1) {
            return $lists[0];
        }

        // Each list is already in registration order; merging has to keep it,
        // because the first route registered is the one that wins.
        $merged = array_merge(...$lists);
        sort($merged);

        return $merged;
    }

    /**
     * Which verbs would have matched this path.
     *
     * Only reached on a miss, so it can afford to try every verb.
     *
     * @return list<string>
     */
    private function methodsFor(string $path, string $except): array
    {
        $allowed = [];

        foreach ($this->knownMethods() as $method) {
            if ($method === $except) {
                continue;
            }

            if ($this->findFor($method, $path) !== null) {
                $allowed[] = $method;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Every verb any route was registered against.
     *
     * @return list<string>
     */
    private function knownMethods(): array
    {
        $methods = [
            ...array_keys($this->static),
            ...array_keys($this->dynamic),
            ...array_keys($this->unbounded),
        ];

        return array_values(array_unique(array_map(strval(...), $methods)));
    }

    /**
     * File a route into the lookup structures, once, at registration.
     */
    private function index(Route $route, int $index): void
    {
        [$minimum, $maximum] = $route->segmentRange();

        foreach ($route->methods as $method) {
            if ($route->isStatic()) {
                // First registration wins, matching the scan it replaces.
                $this->static[$method][$route->uri] ??= $index;

                continue;
            }

            $leading = $route->leadingSegment() ?? '*';

            if ($maximum === null) {
                $this->unbounded[$method][$leading][] = $index;

                continue;
            }

            for ($count = $minimum; $count <= $maximum; $count++) {
                $this->dynamic[$method][$count][$leading][] = $index;
            }
        }
    }

    public function findByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    private function join(string $prefix, string $uri): string
    {
        $joined = '/' . trim($prefix, '/') . '/' . trim($uri, '/');
        $joined = preg_replace('#/+#', '/', $joined) ?? $joined;

        return $joined === '/' ? '/' : rtrim($joined, '/');
    }
}
