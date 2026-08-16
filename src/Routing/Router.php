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

        $this->routes[] = $route;

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
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->match($request->path);

            if ($parameters === null) {
                continue;
            }

            if ($route->acceptsMethod($request->method)) {
                return new RouteMatch($route, $parameters);
            }

            $allowed = [...$allowed, ...$route->methods];
        }

        if ($allowed !== []) {
            throw new HttpException(405, sprintf(
                'The %s route only accepts %s.',
                $request->path,
                implode(', ', array_unique($allowed)),
            ));
        }

        throw HttpException::notFound("No route matches {$request->method} {$request->path}.");
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
