<?php

declare(strict_types=1);

namespace Phpvin;

use Closure;
use Phpvin\Container\Container;
use Phpvin\Http\Commands;
use Phpvin\Http\ExceptionHandler;
use Phpvin\Http\JsonResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Log\FileLogger;
use Phpvin\Middleware\Pipeline;
use Phpvin\RateLimit\RateLimiter;
use Phpvin\Routing\Router;
use Phpvin\Routing\UrlGenerator;
use Phpvin\Validation\Validator;
use Phpvin\View\ViewFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Wires the framework together and turns a Request into a Response.
 *
 * The core knows about HTTP, routing, middleware and a container. Templating
 * and persistence are both optional and both swappable, which is what lets the
 * same class serve a JSON API, a server-rendered site, or one process doing
 * both:
 *
 *     // API only: no templating, no session, no ORM
 *     new Application(__DIR__, [
 *         'views'     => ['engine' => 'none'],
 *         'session'   => false,
 *         'providers' => [],
 *     ]);
 *
 *     // Server-rendered with plain PHP templates
 *     new Application(__DIR__, [
 *         'views' => ['engine' => 'php', 'path' => __DIR__ . '/views'],
 *     ]);
 */
final class Application
{
    public const VERSION = '0.1.0';

    private readonly Container $container;

    /** @var array<string, mixed> */
    private readonly array $config;

    /** @var list<class-string|Closure> */
    private array $middleware = [];

    private bool $booted = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $basePath,
        array $config = [],
    ) {
        $this->config = [...$this->defaultConfig(), ...$config];
        $this->container = new Container();

        $this->registerBindings();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->container->get(Router::class);
    }

    /**
     * @throws RuntimeException in API-only mode, where no engine is configured
     */
    public function views(): ViewFactory
    {
        return $this->container->get(ViewFactory::class);
    }

    public function hasViews(): bool
    {
        return ($this->config('views.engine', 'twig')) !== 'none';
    }

    public function usesSession(): bool
    {
        return (bool) $this->config('session', true);
    }

    public function basePath(string $append = ''): string
    {
        return rtrim($this->basePath, '/') . ($append === '' ? '' : '/' . ltrim($append, '/'));
    }

    /**
     * Read a config value, with `dot.notation` for nested keys.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The middleware every request passes through, outermost first.
     *
     * @param list<class-string|Closure> $middleware
     */
    public function middleware(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * Run the registered service providers. Safe to call more than once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ((array) $this->config('providers', []) as $provider) {
            $instance = is_string($provider) ? $this->container->get($provider) : $provider;

            if (! $instance instanceof ServiceProvider) {
                throw new RuntimeException(sprintf(
                    'A provider must implement %s, got %s.',
                    ServiceProvider::class,
                    get_debug_type($instance),
                ));
            }

            $instance->register($this->container, $this);
        }
    }

    /**
     * Turn a request into a response. Every throwable is converted by the
     * ExceptionHandler, so this never throws.
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        // Bound so controllers and middleware can type-hint Request and get
        // *this* request rather than an autowired empty one.
        $this->container->instance(Request::class, $request);

        try {
            // Global middleware wraps routing as well as the handler, so a 404
            // or a 500 still comes back with your security headers, CORS and
            // request id on it. Only a throw from the global stack itself gets
            // past this.
            $response = (new Pipeline($this->container))
                ->send($request)
                ->through($this->middleware)
                ->then($this->dispatch(...));
        } catch (Throwable $e) {
            $response = $this->renderException($e, $request);
        }

        return $request->isMethod('HEAD') ? $this->withoutBody($response) : $response;
    }

    /**
     * Match the route and run it behind its own middleware.
     */
    private function dispatch(Request $request): Response
    {
        try {
            $match = $this->router()->resolve($request);
            $routed = $request->withRouteParameters($match->parameters);

            $this->container->instance(Request::class, $routed);

            return (new Pipeline($this->container))
                ->send($routed)
                ->through($match->route->middleware)
                ->then(fn (Request $request): Response => $this->toResponse(
                    $this->invoke($match->route->handler, $request),
                ));
        } catch (Throwable $e) {
            return $this->renderException($e, $request);
        }
    }

    private function renderException(Throwable $e, Request $request): Response
    {
        return $this->container->get(ExceptionHandler::class)->render($e, $request);
    }

    /**
     * A HEAD response carries the headers a GET would, including the length,
     * but no body. Handled centrally so no controller has to know.
     */
    private function withoutBody(Response $response): Response
    {
        return $response
            ->header('Content-Length', (string) strlen($response->body()))
            ->setBody('');
    }

    /**
     * Read the request from PHP's superglobals, handle it, and send the
     * response.
     */
    public function run(): void
    {
        $session = null;

        if ($this->usesSession()) {
            $session = $this->container->get(Session::class);
            $session->start();
        }

        $this->handle(Request::fromGlobals($session))->send();
    }

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     */
    private function invoke(array|Closure $handler, Request $request): mixed
    {
        $arguments = $request->routeParameters;

        if ($handler instanceof Closure) {
            return $this->container->call($handler, $arguments);
        }

        [$class, $method] = $handler;

        return $this->container->call([$this->container->get($class), $method], $arguments);
    }

    /**
     * Controllers may return a Response, a Commands list, a string of markup,
     * or anything JSON-encodable.
     */
    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result instanceof Commands => $result->toResponse(),
            is_string($result) => Response::html($result),
            $result === null => new Response('', 204),
            default => new JsonResponse($result),
        };
    }

    private function registerBindings(): void
    {
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(self::class, $this);

        $this->container->singleton(Session::class, static fn (): Session => new Session());
        $this->container->singleton(Router::class, static fn (): Router => new Router());
        $this->container->singleton(Validator::class, static fn (): Validator => new Validator());

        $this->container->singleton(
            UrlGenerator::class,
            fn (Container $c): UrlGenerator => new UrlGenerator(
                $c->get(Router::class),
                (string) $this->config('base_url', ''),
                (string) $this->config('asset_url', ''),
            ),
        );

        $this->container->singleton(
            RateLimiter::class,
            fn (): RateLimiter => new RateLimiter(
                (string) $this->config('rate_limit.path', $this->basePath('storage/rate-limit')),
            ),
        );

        $this->container->singleton(
            LoggerInterface::class,
            fn (): LoggerInterface => ($path = $this->config('log.path')) === null
                ? new NullLogger()
                : new FileLogger((string) $path, (string) $this->config('log.level', LogLevel::DEBUG)),
        );

        $this->container->singleton(
            ExceptionHandler::class,
            fn (Container $c): ExceptionHandler => new ExceptionHandler(
                (bool) $this->config('debug', false),
                // An error handler must never fail while building itself, so a
                // broken view setup degrades to the built-in error page.
                $this->resolveViewsQuietly($c),
                preferJson: ! $this->hasViews(),
                logger: $c->get(LoggerInterface::class),
            ),
        );

        $this->container->singleton(ViewFactory::class, fn (Container $c): ViewFactory
            => $this->makeViews($c) ?? throw new RuntimeException(
                'No view engine is configured, so there is nothing to render with. '
                . "Set views.engine to 'twig', 'php' or 'html' in your config.",
            ));
    }

    private function resolveViewsQuietly(Container $c): ?ViewFactory
    {
        if (! $this->hasViews()) {
            return null;
        }

        try {
            return $c->get(ViewFactory::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function makeViews(Container $c): ?ViewFactory
    {
        $views = ViewFactory::fromConfig(
            (array) $this->config('views', []),
            (bool) $this->config('debug', false),
        );

        if ($views === null) {
            return null;
        }

        if ($views->supportsFunctions()) {
            $urls = $c->get(UrlGenerator::class);
            $views->addFunction('route', $urls->route(...));
            $views->addFunction('asset', $urls->asset(...));
        }

        if ($this->usesSession()) {
            $session = $c->get(Session::class);

            if ($views->supportsFunctions()) {
                $views->addFunction('csrf_token', $session->csrfToken(...));
            }

            // Anything flashed by the previous request, so a redirected-to form
            // can redraw itself without the controller passing it through.
            $views->share('csrf_token', $session->csrfToken());
            $views->share('errors', $session->flashed('errors', []));
            $views->share('old', $session->flashed('old', []));
            $views->share('flash', $session->allFlashed());
        }

        return $views;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfig(): array
    {
        return [
            'debug' => false,
            'base_url' => '',
            'session' => true,
            'database' => null,

            // Referenced by name so the core never hard-depends on the
            // bundled Active Record layer. Set to [] for no persistence, or
            // list your own providers to plug in a different ORM.
            'providers' => [Database\ActiveRecordProvider::class],

            'views' => [
                'engine' => 'twig',
                'path' => $this->basePath('resources/views'),
                'cache' => false,
            ],
        ];
    }
}
