<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\HttpException;
use Phpvin\Http\Request;
use Phpvin\Routing\Router;
use Phpvin\Routing\UrlGenerator;

final class RouterTest extends TestCase
{
    private Router $routes;

    protected function setUp(): void
    {
        $this->routes = new Router();
    }

    #[Test]
    public function it_matches_a_static_path(): void
    {
        $this->routes->get('/about', [PagesController::class, 'about']);

        $match = $this->routes->resolve(Request::create('GET', '/about'));

        $this->assertSame([PagesController::class, 'about'], $match->route->handler);
        $this->assertSame([], $match->parameters);
    }

    #[Test]
    public function it_matches_the_root_path(): void
    {
        $this->routes->get('/', [PagesController::class, 'home'], as: 'home');

        $this->assertSame('home', $this->routes->resolve(Request::create('GET', '/'))->route->name);
    }

    #[Test]
    public function it_extracts_a_path_parameter(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show']);

        $match = $this->routes->resolve(Request::create('GET', '/posts/17'));

        $this->assertSame(['id' => '17'], $match->parameters);
    }

    #[Test]
    public function it_extracts_several_parameters(): void
    {
        $this->routes->get('/{year}/{month}/{slug}', [PagesController::class, 'show']);

        $match = $this->routes->resolve(Request::create('GET', '/2026/08/hello-world'));

        $this->assertSame(['year' => '2026', 'month' => '08', 'slug' => 'hello-world'], $match->parameters);
    }

    #[Test]
    public function a_parameter_does_not_match_across_a_slash(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show']);

        $this->expectException(HttpException::class);

        $this->routes->resolve(Request::create('GET', '/posts/17/edit'));
    }

    #[Test]
    public function it_honours_a_where_constraint(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show'], where: ['id' => '\d+']);

        $this->assertSame(
            ['id' => '9'],
            $this->routes->resolve(Request::create('GET', '/posts/9'))->parameters,
        );

        $this->expectException(HttpException::class);
        $this->routes->resolve(Request::create('GET', '/posts/not-a-number'));
    }

    #[Test]
    public function an_optional_parameter_matches_with_and_without_the_segment(): void
    {
        $this->routes->get('/archive/{page?}', [PagesController::class, 'show']);

        $this->assertSame([], $this->routes->resolve(Request::create('GET', '/archive'))->parameters);
        $this->assertSame(['page' => '3'], $this->routes->resolve(Request::create('GET', '/archive/3'))->parameters);
    }

    #[Test]
    public function a_catch_all_parameter_spans_slashes(): void
    {
        $this->routes->get('/docs/{path*}', [PagesController::class, 'show']);

        $this->assertSame(
            ['path' => 'guide/routing/groups'],
            $this->routes->resolve(Request::create('GET', '/docs/guide/routing/groups'))->parameters,
        );
    }

    #[Test]
    public function it_distinguishes_verbs_on_the_same_path(): void
    {
        $this->routes->get('/posts', [PagesController::class, 'index']);
        $this->routes->post('/posts', [PagesController::class, 'store']);

        $this->assertSame('index', $this->routes->resolve(Request::create('GET', '/posts'))->route->handler[1]);
        $this->assertSame('store', $this->routes->resolve(Request::create('POST', '/posts'))->route->handler[1]);
    }

    #[Test]
    public function head_is_served_by_the_get_route(): void
    {
        $this->routes->get('/posts', [PagesController::class, 'index']);

        $this->assertSame('index', $this->routes->resolve(Request::create('HEAD', '/posts'))->route->handler[1]);
    }

    #[Test]
    public function an_unknown_path_is_a_404(): void
    {
        $this->routes->get('/posts', [PagesController::class, 'index']);

        try {
            $this->routes->resolve(Request::create('GET', '/nope'));
            $this->fail('Expected an HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    #[Test]
    public function a_known_path_with_the_wrong_verb_is_a_405(): void
    {
        $this->routes->get('/posts', [PagesController::class, 'index']);

        try {
            $this->routes->resolve(Request::create('DELETE', '/posts'));
            $this->fail('Expected an HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertStringContainsString('GET', $e->getMessage());
        }
    }

    #[Test]
    public function a_group_applies_its_prefix(): void
    {
        $this->routes->group(prefix: '/admin', define: function (Router $routes): void {
            $routes->get('/users', [PagesController::class, 'index']);
        });

        $this->assertSame('/admin/users', $this->routes->routes()[0]->uri);
    }

    #[Test]
    public function a_group_applies_its_middleware_and_name_prefix(): void
    {
        $this->routes->group(
            prefix: '/admin',
            through: [RecordingMiddleware::class],
            as: 'admin.',
            define: function (Router $routes): void {
                $routes->get('/users', [PagesController::class, 'index'], as: 'users');
            },
        );

        $route = $this->routes->routes()[0];

        $this->assertSame('admin.users', $route->name);
        $this->assertSame([RecordingMiddleware::class], $route->middleware);
    }

    #[Test]
    public function groups_nest(): void
    {
        $this->routes->group(prefix: '/api', as: 'api.', define: function (Router $routes): void {
            $routes->group(prefix: '/v1', as: 'v1.', define: function (Router $routes): void {
                $routes->get('/ping', [PagesController::class, 'index'], as: 'ping');
            });
        });

        $route = $this->routes->routes()[0];

        $this->assertSame('/api/v1/ping', $route->uri);
        $this->assertSame('api.v1.ping', $route->name);
    }

    #[Test]
    public function group_state_is_restored_after_the_closure_runs(): void
    {
        $this->routes->group(prefix: '/admin', define: function (Router $routes): void {
            $routes->get('/users', [PagesController::class, 'index']);
        });

        $this->routes->get('/health', [PagesController::class, 'index']);

        $this->assertSame('/health', $this->routes->routes()[1]->uri);
    }

    #[Test]
    public function group_state_is_restored_even_when_the_closure_throws(): void
    {
        try {
            $this->routes->group(prefix: '/admin', define: function (): void {
                throw new LogicException('boom');
            });
        } catch (LogicException) {
            // expected
        }

        $this->routes->get('/health', [PagesController::class, 'index']);

        $this->assertSame('/health', $this->routes->routes()[0]->uri);
    }

    #[Test]
    public function duplicate_route_names_are_rejected(): void
    {
        $this->routes->get('/a', [PagesController::class, 'index'], as: 'same');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('both named [same]');

        $this->routes->get('/b', [PagesController::class, 'index'], as: 'same');
    }

    #[Test]
    public function one_handler_can_serve_several_verbs(): void
    {
        $this->routes->on(['GET', 'POST'], '/search', [PagesController::class, 'index']);

        $this->assertSame('/search', $this->routes->resolve(Request::create('GET', '/search'))->route->uri);
        $this->assertSame('/search', $this->routes->resolve(Request::create('POST', '/search'))->route->uri);
    }

    #[Test]
    public function it_builds_a_url_from_a_route_name(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show'], as: 'posts.show');
        $urls = new UrlGenerator($this->routes);

        $this->assertSame('/posts/7', $urls->route('posts.show', ['id' => 7]));
    }

    #[Test]
    public function leftover_parameters_become_a_query_string(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show'], as: 'posts.show');
        $urls = new UrlGenerator($this->routes);

        $this->assertSame('/posts/7?ref=email', $urls->route('posts.show', ['id' => 7, 'ref' => 'email']));
    }

    #[Test]
    public function building_a_url_without_a_required_parameter_fails_loudly(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show'], as: 'posts.show');
        $urls = new UrlGenerator($this->routes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing parameter [id]');

        $urls->route('posts.show');
    }

    #[Test]
    public function an_optional_parameter_can_be_left_out_of_a_url(): void
    {
        $this->routes->get('/archive/{page?}', [PagesController::class, 'show'], as: 'archive');
        $urls = new UrlGenerator($this->routes);

        $this->assertSame('/archive', $urls->route('archive'));
        $this->assertSame('/archive/2', $urls->route('archive', ['page' => 2]));
    }

    #[Test]
    public function a_route_handler_must_be_a_class_method_pair(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentionally wrong */
        $this->routes->get('/bad', [PagesController::class]);
    }
}

class PagesController
{
    public function home(): string
    {
        return 'home';
    }

    public function about(): string
    {
        return 'about';
    }

    public function index(): string
    {
        return 'index';
    }

    public function store(): string
    {
        return 'store';
    }

    public function show(int $id): string
    {
        return "show:$id";
    }
}
