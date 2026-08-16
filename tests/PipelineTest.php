<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Container\Container;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Middleware\Middleware;
use Phpvin\Middleware\Pipeline;
use RuntimeException;

final class PipelineTest extends TestCase
{
    private function pipeline(): Pipeline
    {
        return new Pipeline(new Container());
    }

    #[Test]
    public function it_reaches_the_destination_with_no_middleware(): void
    {
        $response = $this->pipeline()
            ->send(Request::create('GET', '/'))
            ->through([])
            ->then(fn (): Response => Response::html('done'));

        $this->assertSame('done', $response->body());
    }

    #[Test]
    public function middleware_wraps_the_destination_on_both_sides(): void
    {
        RecordingMiddleware::$log = [];

        $response = $this->pipeline()
            ->send(Request::create('GET', '/'))
            ->through([new RecordingMiddleware('outer'), new RecordingMiddleware('inner')])
            ->then(function (): Response {
                RecordingMiddleware::$log[] = 'handler';

                return Response::html('done');
            });

        // This is the assertion the old framework could not have made: the
        // response passes back out through every layer in reverse.
        $this->assertSame(
            ['outer:in', 'inner:in', 'handler', 'inner:out', 'outer:out'],
            RecordingMiddleware::$log,
        );
        $this->assertSame('done', $response->body());
    }

    #[Test]
    public function middleware_can_modify_the_response_on_the_way_out(): void
    {
        $response = $this->pipeline()
            ->send(Request::create('GET', '/'))
            ->through([
                function (Request $request, Closure $next): Response {
                    return $next($request)->header('X-Stamped', 'yes');
                },
            ])
            ->then(fn (): Response => Response::html('body'));

        $this->assertSame('yes', $response->getHeader('X-Stamped'));
    }

    #[Test]
    public function middleware_can_short_circuit_without_calling_next(): void
    {
        RecordingMiddleware::$log = [];

        $response = $this->pipeline()
            ->send(Request::create('GET', '/'))
            ->through([
                fn (): Response => Response::html('blocked')->setStatus(403),
                new RecordingMiddleware('never'),
            ])
            ->then(fn (): Response => Response::html('handler'));

        $this->assertSame(403, $response->status());
        $this->assertSame('blocked', $response->body());
        $this->assertSame([], RecordingMiddleware::$log);
    }

    #[Test]
    public function middleware_can_replace_the_request_it_passes_on(): void
    {
        $response = $this->pipeline()
            ->send(Request::create('GET', '/'))
            ->through([
                fn (Request $request, Closure $next): Response
                    => $next($request->withRouteParameters(['id' => '99'])),
            ])
            ->then(fn (Request $request): Response => Response::html((string) $request->routeParameter('id')));

        $this->assertSame('99', $response->body());
    }

    #[Test]
    public function class_name_middleware_is_resolved_through_the_container(): void
    {
        $container = new Container();
        $container->bind(CountingMiddleware::class, fn (): CountingMiddleware => new CountingMiddleware());
        CountingMiddleware::$calls = 0;

        (new Pipeline($container))
            ->send(Request::create('GET', '/'))
            ->through([CountingMiddleware::class])
            ->then(fn (): Response => Response::html('ok'));

        $this->assertSame(1, CountingMiddleware::$calls);
    }

    #[Test]
    public function a_non_middleware_layer_is_rejected_with_a_clear_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        (new Pipeline(new Container()))
            ->send(Request::create('GET', '/'))
            ->through([PagesController::class])
            ->then(fn (): Response => Response::html('ok'));
    }
}

class RecordingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $label = 'anon') {}

    public function process(Request $request, Closure $next): Response
    {
        self::$log[] = "{$this->label}:in";
        $response = $next($request);
        self::$log[] = "{$this->label}:out";

        return $response;
    }
}

class CountingMiddleware implements Middleware
{
    public static int $calls = 0;

    public function process(Request $request, Closure $next): Response
    {
        self::$calls++;

        return $next($request);
    }
}
