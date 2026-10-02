<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Middleware\Middleware;
use Phpvin\Middleware\VerifyCsrfToken;
use Phpvin\Validation\Validator;
use Phpvin\View\ViewFactory;
use RuntimeException;

final class ApplicationTest extends TestCase
{
    private function app(array $config = []): Application
    {
        return new Application(__DIR__ . '/fixtures', [
            'debug' => false,
            'views' => ['path' => __DIR__ . '/fixtures/views'],
            ...$config,
        ]);
    }

    #[Test]
    public function a_string_return_becomes_an_html_response(): void
    {
        $app = $this->app();
        $app->router()->get('/', fn (): string => 'hello');

        $response = $app->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->status());
        $this->assertSame('hello', $response->body());
        $this->assertStringContainsString('text/html', (string) $response->getHeader('content-type'));
    }

    #[Test]
    public function an_array_return_becomes_a_json_response(): void
    {
        $app = $this->app();
        $app->router()->get('/api/ping', fn (): array => ['pong' => true]);

        $response = $app->handle(Request::create('GET', '/api/ping'));

        $this->assertSame('{"pong":true}', $response->body());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('content-type'));
    }

    #[Test]
    public function a_null_return_becomes_a_204(): void
    {
        $app = $this->app();
        $app->router()->delete('/thing', fn () => null);

        $this->assertSame(204, $app->handle(Request::create('DELETE', '/thing'))->status());
    }

    #[Test]
    public function route_parameters_are_passed_as_typed_arguments(): void
    {
        $app = $this->app();
        $app->router()->get('/posts/{id}', fn (int $id): string => 'id=' . var_export($id, true));

        $this->assertSame('id=17', $app->handle(Request::create('GET', '/posts/17'))->body());
    }

    #[Test]
    public function the_current_request_is_injected_by_type_hint(): void
    {
        $app = $this->app();
        $app->router()->post('/echo', fn (Request $request): string => (string) $request->input('word'));

        $response = $app->handle(Request::create('POST', '/echo', body: ['word' => 'ping']));

        $this->assertSame('ping', $response->body());
    }

    #[Test]
    public function a_controller_gets_its_dependencies_injected(): void
    {
        $app = $this->app();
        $app->router()->post('/register', [SignupController::class, 'store']);

        $response = $app->handle(Request::create('POST', '/register', body: ['email' => 'a@b.co']));

        $this->assertSame('registered a@b.co', $response->body());
    }

    #[Test]
    public function an_unknown_path_renders_a_404(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/nowhere'));

        $this->assertSame(404, $response->status());
    }

    #[Test]
    public function a_wrong_verb_renders_a_405(): void
    {
        $app = $this->app();
        $app->router()->get('/only-get', fn (): string => 'ok');

        $this->assertSame(405, $app->handle(Request::create('POST', '/only-get'))->status());
    }

    #[Test]
    public function a_thrown_error_becomes_a_500_without_leaking_the_message(): void
    {
        $app = $this->app();
        $app->router()->get('/boom', function (): never {
            throw new RuntimeException('database password is hunter2');
        });

        $response = $app->handle(Request::create('GET', '/boom'));

        $this->assertSame(500, $response->status());
        $this->assertStringNotContainsString('hunter2', $response->body());
    }

    #[Test]
    public function debug_mode_shows_the_error_detail(): void
    {
        $app = $this->app(['debug' => true]);
        $app->router()->get('/boom', function (): never {
            throw new RuntimeException('the actual problem');
        });

        $this->assertStringContainsString('the actual problem', $app->handle(Request::create('GET', '/boom'))->body());
    }

    #[Test]
    public function an_api_client_gets_json_errors(): void
    {
        $response = $this->app()->handle(
            Request::create('GET', '/nowhere', headers: ['accept' => 'application/json']),
        );

        $this->assertSame(404, $response->status());
        $this->assertSame(404, json_decode($response->body(), true)['status']);
    }

    #[Test]
    public function global_middleware_runs_on_every_route(): void
    {
        $app = $this->app();
        $app->middleware([StampMiddleware::class]);
        $app->router()->get('/', fn (): string => 'hello');

        $this->assertSame('yes', $app->handle(Request::create('GET', '/'))->getHeader('x-stamped'));
    }

    #[Test]
    public function route_middleware_runs_after_global_middleware(): void
    {
        RecordingMiddleware::$log = [];

        $app = $this->app();
        $app->middleware([new RecordingMiddleware('global')]);
        $app->router()->get('/', fn (): string => 'hello', through: [new RecordingMiddleware('route')]);

        $app->handle(Request::create('GET', '/'));

        $this->assertSame(
            ['global:in', 'route:in', 'route:out', 'global:out'],
            RecordingMiddleware::$log,
        );
    }

    #[Test]
    public function csrf_middleware_blocks_a_post_with_no_token(): void
    {
        $app = $this->app();
        $app->middleware([new VerifyCsrfToken()]);
        $app->router()->post('/save', fn (): string => 'saved');

        $response = $app->handle(
            Request::create('POST', '/save', session: new Session([])),
        );

        $this->assertSame(419, $response->status());
    }

    #[Test]
    public function csrf_middleware_allows_a_post_with_the_right_token(): void
    {
        $session = new Session([]);

        $app = $this->app();
        $app->middleware([new VerifyCsrfToken()]);
        $app->router()->post('/save', fn (): string => 'saved');

        $response = $app->handle(Request::create(
            'POST',
            '/save',
            body: ['_token' => $session->csrfToken()],
            session: $session,
        ));

        $this->assertSame('saved', $response->body());
    }

    #[Test]
    public function csrf_middleware_leaves_reads_alone(): void
    {
        $app = $this->app();
        $app->middleware([new VerifyCsrfToken()]);
        $app->router()->get('/', fn (): string => 'ok');

        $this->assertSame(200, $app->handle(Request::create('GET', '/', session: new Session([])))->status());
    }

    #[Test]
    public function csrf_middleware_can_exempt_a_path(): void
    {
        $app = $this->app();
        $app->middleware([new VerifyCsrfToken(['/webhooks/*'])]);
        $app->router()->post('/webhooks/stripe', fn (): string => 'received');

        $response = $app->handle(Request::create('POST', '/webhooks/stripe', session: new Session([])));

        $this->assertSame('received', $response->body());
    }

    #[Test]
    public function a_failed_validation_redirects_back_with_errors_flashed(): void
    {
        $session = new Session([]);

        $app = $this->app();
        $app->router()->post('/register', function (Request $request, Validator $validator): never {
            $validator->validate($request->all(), ['email' => 'required|email']);

            throw new LogicException('unreachable');
        });

        $response = $app->handle(Request::create(
            'POST',
            '/register',
            body: ['email' => 'not-an-email'],
            headers: ['referer' => '/register'],
            session: $session,
        ));

        $this->assertSame(303, $response->status());
        $this->assertSame('/register', $response->getHeader('location'));

        $next = new Session($session->all());
        $this->assertArrayHasKey('email', $next->flashed('errors'));
        $this->assertSame(['email' => 'not-an-email'], $next->flashed('old'));
    }

    #[Test]
    public function a_failed_validation_returns_422_json_for_api_clients(): void
    {
        $app = $this->app();
        $app->router()->post('/api/register', function (Request $request, Validator $validator): never {
            $validator->validate($request->all(), ['email' => 'required|email']);

            throw new LogicException('unreachable');
        });

        $response = $app->handle(Request::create(
            'POST',
            '/api/register',
            headers: ['accept' => 'application/json'],
        ));

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('email', json_decode($response->body(), true)['errors']);
    }

    #[Test]
    public function views_render_and_can_build_urls_by_route_name(): void
    {
        $app = $this->app();
        $app->router()->get('/greet/{name}', fn (ViewFactory $views, string $name): Response
            => $views->response('greeting', ['name' => $name]), as: 'greeting');

        $body = $app->handle(Request::create('GET', '/greet/grace'))->body();

        $this->assertStringContainsString('<h1>Hello, grace</h1>', $body);
        $this->assertStringContainsString('href="/greet/ada"', $body);
    }

    #[Test]
    public function template_output_is_escaped(): void
    {
        $app = $this->app();
        $app->router()->get('/greet/{name}', fn (ViewFactory $views, string $name): Response
            => $views->response('greeting', ['name' => $name]), as: 'greeting');

        $body = $app->handle(Request::create('GET', '/greet/' . urlencode('<script>x</script>')))->body();

        $this->assertStringNotContainsString('<script>x</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function a_head_request_returns_the_headers_without_the_body(): void
    {
        $app = $this->app();
        $app->router()->get('/', fn (): string => str_repeat('x', 5000));

        $head = $app->handle(Request::create('HEAD', '/'));

        // The route is served by the GET handler, but sending 5 kB of body on
        // a HEAD is wasted bandwidth on every health check and link preview.
        $this->assertSame(200, $head->status());
        $this->assertSame('', $head->body());
        $this->assertSame('5000', $head->getHeader('Content-Length'));
    }

    #[Test]
    public function a_get_request_still_has_its_body(): void
    {
        $app = $this->app();
        $app->router()->get('/', fn (): string => 'hello');

        $this->assertSame('hello', $app->handle(Request::create('GET', '/'))->body());
    }

    #[Test]
    public function a_head_request_to_a_missing_route_is_also_bodiless(): void
    {
        $this->assertSame('', $this->app()->handle(Request::create('HEAD', '/nowhere'))->body());
    }

    #[Test]
    public function config_reads_nested_keys_with_dot_notation(): void
    {
        $app = $this->app(['mail' => ['from' => 'hi@example.com']]);

        $this->assertSame('hi@example.com', $app->config('mail.from'));
        $this->assertSame('fallback', $app->config('mail.missing', 'fallback'));
        $this->assertSame('fallback', $app->config('nothing.here', 'fallback'));
    }
}

class SignupController
{
    public function __construct(private readonly Validator $validator) {}

    public function store(Request $request): string
    {
        $clean = $this->validator->validate($request->all(), ['email' => 'required|email']);

        return 'registered ' . $clean['email'];
    }
}

class StampMiddleware implements Middleware
{
    public function process(Request $request, Closure $next): Response
    {
        return $next($request)->header('X-Stamped', 'yes');
    }
}
