<?php

declare(strict_types=1);

namespace Phpvin\Middleware;

use Closure;
use Phpvin\Container\Container;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use RuntimeException;

/**
 * Runs a request through a stack of middleware and out to a destination.
 *
 * The stack is a genuine onion: every layer sees the request on the way in and
 * the response on the way out.
 */
final class Pipeline
{
    private Request $request;

    /** @var list<class-string<Middleware>|Middleware|Closure> */
    private array $middleware = [];

    public function __construct(private readonly Container $container) {}

    public function send(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @param list<class-string<Middleware>|Middleware|Closure> $middleware
     */
    public function through(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * @param Closure(Request): Response $destination
     */
    public function then(Closure $destination): Response
    {
        $stack = array_reduce(
            array_reverse($this->middleware),
            fn (Closure $next, $layer): Closure
                => fn (Request $request): Response => $this->invoke($layer, $request, $next),
            $destination,
        );

        return $stack($this->request);
    }

    private function invoke(mixed $layer, Request $request, Closure $next): Response
    {
        if ($layer instanceof Closure) {
            return $layer($request, $next);
        }

        if (is_string($layer)) {
            $layer = $this->container->get($layer);
        }

        if (! $layer instanceof Middleware) {
            throw new RuntimeException(sprintf(
                'Middleware must implement %s, got %s.',
                Middleware::class,
                get_debug_type($layer),
            ));
        }

        return $layer->process($request, $next);
    }
}
