<?php

declare(strict_types=1);

namespace Phpvin\Console\Commands;

use Closure;
use Phpvin\Console\Command;
use Phpvin\Console\Input;
use Phpvin\Console\Output;
use Phpvin\Routing\Router;

/**
 * Every registered route, in the order the router will try them.
 *
 * Order matters (the first match wins), so this deliberately does not sort.
 */
final class RouteListCommand implements Command
{
    public function __construct(private readonly Router $router) {}

    public function name(): string
    {
        return 'route:list';
    }

    public function description(): string
    {
        return 'Show every registered route, in matching order';
    }

    public function run(Input $input, Output $output): int
    {
        $routes = $this->router->routes();

        if ($routes === []) {
            $output->warn('No routes are registered.');

            return 0;
        }

        $filter = $input->option('path');
        $rows = [];

        foreach ($routes as $route) {
            if ($filter !== null && ! str_contains($route->uri, $filter)) {
                continue;
            }

            $rows[] = [
                $output->paint(implode('|', $route->methods), 'blue'),
                $route->uri,
                $route->name ?? $output->paint('-', 'grey'),
                $this->describeHandler($route->handler),
                $this->describeMiddleware($route->middleware, $output),
            ];
        }

        $output->table(['Method', 'URI', 'Name', 'Handler', 'Middleware'], $rows);
        $output->line();
        $output->muted(sprintf('%d route%s', count($rows), count($rows) === 1 ? '' : 's'));

        return 0;
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     */
    private function describeHandler(array|Closure $handler): string
    {
        if ($handler instanceof Closure) {
            return 'Closure';
        }

        [$class, $method] = $handler;

        return $this->shortName($class) . '@' . $method;
    }

    /**
     * @param list<class-string|Closure> $middleware
     */
    private function describeMiddleware(array $middleware, Output $output): string
    {
        if ($middleware === []) {
            return $output->paint('-', 'grey');
        }

        return implode(', ', array_map(
            fn (mixed $item): string => is_string($item) ? $this->shortName($item) : 'Closure',
            $middleware,
        ));
    }

    private function shortName(string $class): string
    {
        return str_contains($class, '\\') ? substr(strrchr($class, '\\') ?: '', 1) : $class;
    }
}
