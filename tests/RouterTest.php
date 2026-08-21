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

    // --- matching order ------------------------------------------------------
    //
    // The router indexes routes rather than scanning them, which is only safe
    // if the indexing cannot change which route wins. First registered wins,
    // whatever shape it is.

    #[Test]
    public function a_dynamic_route_registered_first_beats_a_later_static_one(): void
    {
        $this->routes->get('/posts/{slug}', [PagesController::class, 'show'], as: 'dynamic');
        $this->routes->get('/posts/new', [PagesController::class, 'index'], as: 'static');

        $this->assertSame('dynamic', $this->routes->resolve(Request::create('GET', '/posts/new'))->route->name);
    }

    #[Test]
    public function a_static_route_registered_first_beats_a_later_dynamic_one(): void
    {
        $this->routes->get('/posts/new', [PagesController::class, 'index'], as: 'static');
        $this->routes->get('/posts/{slug}', [PagesController::class, 'show'], as: 'dynamic');

        $this->assertSame('static', $this->routes->resolve(Request::create('GET', '/posts/new'))->route->name);
        $this->assertSame('dynamic', $this->routes->resolve(Request::create('GET', '/posts/other'))->route->name);
    }

    #[Test]
    public function the_first_of_two_matching_dynamic_routes_wins(): void
    {
        $this->routes->get('/{a}/{b}', [PagesController::class, 'index'], as: 'first');
        $this->routes->get('/{c}/{d}', [PagesController::class, 'show'], as: 'second');

        $this->assertSame('first', $this->routes->resolve(Request::create('GET', '/x/y'))->route->name);
    }

    #[Test]
    public function a_catch_all_registered_first_beats_a_later_specific_route(): void
    {
        $this->routes->get('/docs/{path*}', [PagesController::class, 'show'], as: 'catchall');
        $this->routes->get('/docs/intro', [PagesController::class, 'index'], as: 'specific');

        $this->assertSame('catchall', $this->routes->resolve(Request::create('GET', '/docs/intro'))->route->name);
    }

    #[Test]
    public function a_specific_route_registered_first_beats_a_later_catch_all(): void
    {
        $this->routes->get('/docs/intro', [PagesController::class, 'index'], as: 'specific');
        $this->routes->get('/docs/{path*}', [PagesController::class, 'show'], as: 'catchall');

        $this->assertSame('specific', $this->routes->resolve(Request::create('GET', '/docs/intro'))->route->name);
        $this->assertSame('catchall', $this->routes->resolve(Request::create('GET', '/docs/a/b/c'))->route->name);
    }

    #[Test]
    public function a_route_whose_first_segment_is_a_placeholder_is_still_tried(): void
    {
        // These cannot be filed under a literal prefix, so they have to be
        // tried for every path of the right shape.
        $this->routes->get('/{tenant}/settings', [PagesController::class, 'show'], as: 'tenant');

        $this->assertSame('tenant', $this->routes->resolve(Request::create('GET', '/acme/settings'))->route->name);
        $this->assertSame('tenant', $this->routes->resolve(Request::create('GET', '/other/settings'))->route->name);
    }

    #[Test]
    public function a_placeholder_first_segment_competes_on_registration_order(): void
    {
        $this->routes->get('/{tenant}/settings', [PagesController::class, 'show'], as: 'tenant');
        $this->routes->get('/acme/settings', [PagesController::class, 'index'], as: 'literal');

        $this->assertSame('tenant', $this->routes->resolve(Request::create('GET', '/acme/settings'))->route->name);
    }

    #[Test]
    public function an_optional_segment_matches_both_lengths(): void
    {
        $this->routes->get('/archive/{year?}', [PagesController::class, 'show'], as: 'archive');

        $this->assertSame('archive', $this->routes->resolve(Request::create('GET', '/archive'))->route->name);
        $this->assertSame('archive', $this->routes->resolve(Request::create('GET', '/archive/2026'))->route->name);
    }

    #[Test]
    public function a_constraint_that_fails_falls_through_to_the_next_route(): void
    {
        $this->routes->get('/posts/{id}', [PagesController::class, 'show'], as: 'numeric', where: ['id' => '\d+']);
        $this->routes->get('/posts/{slug}', [PagesController::class, 'index'], as: 'slug');

        $this->assertSame('numeric', $this->routes->resolve(Request::create('GET', '/posts/7'))->route->name);
        $this->assertSame('slug', $this->routes->resolve(Request::create('GET', '/posts/hello'))->route->name);
    }

    #[Test]
    public function head_falls_back_to_get_even_when_another_verb_matches_first(): void
    {
        $this->routes->post('/thing', [PagesController::class, 'store'], as: 'post');
        $this->routes->get('/thing', [PagesController::class, 'index'], as: 'get');

        $this->assertSame('get', $this->routes->resolve(Request::create('HEAD', '/thing'))->route->name);
    }

    #[Test]
    public function a_405_lists_every_verb_that_would_have_matched(): void
    {
        $this->routes->get('/thing', [PagesController::class, 'index']);
        $this->routes->post('/thing', [PagesController::class, 'store']);
        $this->routes->get('/other', [PagesController::class, 'index']);

        try {
            $this->routes->resolve(Request::create('DELETE', '/thing'));
            $this->fail('Expected a 405.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
            $this->assertStringContainsString('GET', $e->getMessage());
            $this->assertStringContainsString('POST', $e->getMessage());
        }
    }

    #[Test]
    public function a_405_is_reported_for_a_dynamic_path_too(): void
    {
        $this->routes->post('/posts/{id}', [PagesController::class, 'store'], where: ['id' => '\d+']);

        try {
            $this->routes->resolve(Request::create('GET', '/posts/7'));
            $this->fail('Expected a 405.');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
        }
    }

    #[Test]
    public function a_literal_prefix_and_a_placeholder_prefix_compete_on_order(): void
    {
        // Both are dynamic and both could match, but they are filed under
        // different prefixes, so both buckets have to be consulted, and the
        // earlier registration still has to win.
        $this->routes->get('/{tenant}/{page}', [PagesController::class, 'show'], as: 'wildcard');
        $this->routes->get('/acme/{page}', [PagesController::class, 'index'], as: 'literal');

        $this->assertSame('wildcard', $this->routes->resolve(Request::create('GET', '/acme/settings'))->route->name);
    }

    #[Test]
    public function the_literal_prefix_wins_when_it_was_registered_first(): void
    {
        $this->routes->get('/acme/{page}', [PagesController::class, 'index'], as: 'literal');
        $this->routes->get('/{tenant}/{page}', [PagesController::class, 'show'], as: 'wildcard');

        $this->assertSame('literal', $this->routes->resolve(Request::create('GET', '/acme/settings'))->route->name);
        $this->assertSame('wildcard', $this->routes->resolve(Request::create('GET', '/other/settings'))->route->name);
    }

    #[Test]
    public function a_catch_all_and_a_sized_route_are_both_considered(): void
    {
        $this->routes->get('/files/{path*}', [PagesController::class, 'show'], as: 'catchall');
        $this->routes->get('/files/{name}', [PagesController::class, 'index'], as: 'single');

        // Same prefix, different buckets: one unbounded, one two-segment.
        $this->assertSame('catchall', $this->routes->resolve(Request::create('GET', '/files/a.txt'))->route->name);
        $this->assertSame('catchall', $this->routes->resolve(Request::create('GET', '/files/a/b'))->route->name);
    }

    #[Test]
    public function a_large_route_table_still_matches_the_right_one(): void
    {
        // The indexing only pays off at scale, so it should be checked there.
        for ($i = 0; $i < 300; $i++) {
            $this->routes->get("/resource-$i", [PagesController::class, 'index'], as: "static.$i");
            $this->routes->get("/resource-$i/{id}", [PagesController::class, 'show'], as: "dynamic.$i");
        }

        $this->assertSame('static.0', $this->routes->resolve(Request::create('GET', '/resource-0'))->route->name);
        $this->assertSame('static.299', $this->routes->resolve(Request::create('GET', '/resource-299'))->route->name);

        $match = $this->routes->resolve(Request::create('GET', '/resource-150/42'));

        $this->assertSame('dynamic.150', $match->route->name);
        $this->assertSame(['id' => '42'], $match->parameters);
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
