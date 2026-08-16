<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Container\Container;
use Phpvin\Database\Connection;
use Phpvin\Database\Model;
use Phpvin\Http\Request;
use Phpvin\ServiceProvider;
use Phpvin\Validation\Validator;
use RuntimeException;

/**
 * The framework has to work three ways: as a JSON API with no templating, as a
 * server-rendered site, and as one process doing both.
 */
final class ApiModeTest extends TestCase
{
    private function api(array $config = []): Application
    {
        return new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'providers' => [],
            ...$config,
        ]);
    }

    #[Test]
    public function an_api_only_app_needs_no_view_engine(): void
    {
        $app = $this->api();
        $app->router()->get('/users', fn (): array => [['id' => 1, 'name' => 'ada']]);

        $response = $app->handle(Request::create('GET', '/users'));

        $this->assertSame('[{"id":1,"name":"ada"}]', $response->body());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('content-type'));
    }

    #[Test]
    public function an_api_only_app_returns_json_errors_even_without_an_accept_header(): void
    {
        $response = $this->api()->handle(Request::create('GET', '/missing'));

        $this->assertSame(404, $response->status());
        $this->assertSame(404, json_decode($response->body(), true)['status']);
    }

    #[Test]
    public function an_api_only_app_returns_json_validation_errors(): void
    {
        $app = $this->api();
        $app->router()->post('/users', function (Request $request, Validator $validator): never {
            $validator->validate($request->all(), ['email' => 'required|email']);

            throw new LogicException('unreachable');
        });

        $response = $app->handle(Request::create('POST', '/users'));

        $this->assertSame(422, $response->status());
        $this->assertArrayHasKey('email', json_decode($response->body(), true)['errors']);
    }

    #[Test]
    public function asking_for_views_in_an_api_only_app_explains_the_problem(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No view engine is configured');

        $this->api()->views();
    }

    #[Test]
    public function an_api_only_app_reports_that_it_has_no_views_or_session(): void
    {
        $app = $this->api();

        $this->assertFalse($app->hasViews());
        $this->assertFalse($app->usesSession());
    }

    #[Test]
    public function one_app_can_serve_both_html_pages_and_a_json_api(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'php', 'path' => __DIR__ . '/fixtures/views'],
            'providers' => [],
        ]);

        $app->router()->get('/', fn (): string => '<h1>Home</h1>');
        $app->router()->group(prefix: '/api', define: function ($routes): void {
            $routes->get('/users', fn (): array => ['users' => []]);
        });

        $page = $app->handle(Request::create('GET', '/'));
        $json = $app->handle(Request::create('GET', '/api/users'));

        $this->assertStringContainsString('text/html', (string) $page->getHeader('content-type'));
        $this->assertStringContainsString('application/json', (string) $json->getHeader('content-type'));
    }

    #[Test]
    public function an_html_app_still_answers_json_to_clients_that_ask_for_it(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'php', 'path' => __DIR__ . '/fixtures/views'],
            'providers' => [],
        ]);

        $html = $app->handle(Request::create('GET', '/missing'));
        $json = $app->handle(Request::create('GET', '/missing', headers: ['accept' => 'application/json']));

        $this->assertStringContainsString('<!doctype html>', $html->body());
        $this->assertJson($json->body());
    }

    // --- Providers --------------------------------------------------------

    #[Test]
    public function no_providers_means_no_orm_is_wired_at_all(): void
    {
        Model::useConnection(null);

        // The database config is present, but with no provider listed nothing
        // reads it, which is what makes the bundled ORM removable.
        $app = $this->api(['database' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $app->boot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Model::useConnection');

        Model::query();
    }

    #[Test]
    public function the_active_record_provider_wires_the_connection(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        $app->boot();

        $this->assertInstanceOf(Connection::class, $app->container()->get(Connection::class));

        Model::useConnection(null);
    }

    #[Test]
    public function a_custom_provider_can_register_anything(): void
    {
        $app = $this->api(['providers' => [RecordingProvider::class]]);
        RecordingProvider::$registered = false;

        $app->boot();

        $this->assertTrue(RecordingProvider::$registered);
        $this->assertSame('plugged in', $app->container()->get('custom.service'));
    }

    #[Test]
    public function providers_only_run_once(): void
    {
        $app = $this->api(['providers' => [CountingProvider::class]]);
        CountingProvider::$calls = 0;

        $app->boot();
        $app->boot();
        $app->handle(Request::create('GET', '/'));

        $this->assertSame(1, CountingProvider::$calls);
    }

    #[Test]
    public function a_provider_that_does_not_implement_the_interface_is_rejected(): void
    {
        $app = $this->api(['providers' => [PagesController::class]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $app->boot();
    }
}

class RecordingProvider implements ServiceProvider
{
    public static bool $registered = false;

    public function register(Container $container, Application $app): void
    {
        self::$registered = true;
        $container->instance('custom.service', 'plugged in');
    }
}

class CountingProvider implements ServiceProvider
{
    public static int $calls = 0;

    public function register(Container $container, Application $app): void
    {
        self::$calls++;
    }
}
