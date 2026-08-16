<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Asset\Assets;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Middleware\UnobtrusiveJavaScript;
use Phpvin\Middleware\VerifyCsrfToken;
use Phpvin\View\ViewFactory;

/**
 * The server half of the jQuery layer.
 */
final class UnobtrusiveJavaScriptTest extends TestCase
{
    private function app(array $config = []): Application
    {
        return new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'php', 'path' => __DIR__ . '/fixtures/views'],
            'providers' => [],
            ...$config,
        ]);
    }

    private function ajax(string $method, string $path, array $body = [], ?Session $session = null): Request
    {
        return Request::create(
            $method,
            $path,
            body: $body,
            headers: ['x-requested-with' => 'XMLHttpRequest'],
            session: $session,
        );
    }

    // --- redirects --------------------------------------------------------

    #[Test]
    public function a_redirect_during_an_ajax_request_becomes_a_204_with_a_location_header(): void
    {
        $app = $this->app();
        $app->middleware([UnobtrusiveJavaScript::class]);
        $app->router()->post('/notes', fn (): Response => new RedirectResponse('/dashboard', 303));

        $response = $app->handle($this->ajax('POST', '/notes'));

        // XHR follows redirects itself and would hand jQuery a whole HTML
        // document to swap into a <div>. The header avoids that.
        $this->assertSame(204, $response->status());
        $this->assertSame('/dashboard', $response->getHeader(UnobtrusiveJavaScript::LOCATION_HEADER));
        $this->assertSame('', $response->body());
    }

    #[Test]
    public function a_redirect_on_a_normal_request_is_left_alone(): void
    {
        $app = $this->app();
        $app->middleware([UnobtrusiveJavaScript::class]);
        $app->router()->post('/notes', fn (): Response => new RedirectResponse('/dashboard', 303));

        $response = $app->handle(Request::create('POST', '/notes'));

        $this->assertSame(303, $response->status());
        $this->assertSame('/dashboard', $response->getHeader('location'));
        $this->assertNull($response->getHeader(UnobtrusiveJavaScript::LOCATION_HEADER));
    }

    #[Test]
    public function a_non_redirect_ajax_response_is_left_alone(): void
    {
        $app = $this->app();
        $app->middleware([UnobtrusiveJavaScript::class]);
        $app->router()->post('/notes', fn (): string => '<li>a note</li>');

        $response = $app->handle($this->ajax('POST', '/notes'));

        $this->assertSame(200, $response->status());
        $this->assertSame('<li>a note</li>', $response->body());
    }

    // --- fragments --------------------------------------------------------

    #[Test]
    public function a_fragment_is_marked_so_it_can_be_told_apart_from_a_page(): void
    {
        $app = $this->app();
        $app->router()->post('/notes', fn (ViewFactory $views): Response
            => $views->fragment('fragment', ['name' => 'ada'], 201));

        $response = $app->handle($this->ajax('POST', '/notes'));

        $this->assertSame(201, $response->status());
        $this->assertSame('1', $response->getHeader('X-Phpvin-Fragment'));
        $this->assertStringContainsString('X-Requested-With', (string) $response->getHeader('Vary'));
    }

    #[Test]
    public function a_fragment_renders_without_a_layout(): void
    {
        $app = $this->app();
        $app->router()->post('/notes', fn (ViewFactory $views): Response
            => $views->fragment('fragment', ['name' => 'ada']));

        $body = $app->handle($this->ajax('POST', '/notes'))->body();

        $this->assertStringContainsString('<li>ada</li>', $body);
        $this->assertStringNotContainsString('<!doctype html>', $body);
    }

    // --- CSRF over AJAX ---------------------------------------------------

    #[Test]
    public function the_csrf_token_is_accepted_from_the_x_csrf_token_header(): void
    {
        $session = new Session([]);

        $app = $this->app();
        $app->middleware([VerifyCsrfToken::class]);
        $app->router()->post('/notes', fn (): string => 'saved');

        $response = $app->handle(Request::create(
            'POST',
            '/notes',
            headers: [
                'x-requested-with' => 'XMLHttpRequest',
                'x-csrf-token' => $session->csrfToken(),
            ],
            session: $session,
        ));

        $this->assertSame('saved', $response->body());
    }

    #[Test]
    public function an_ajax_post_without_the_header_is_still_rejected(): void
    {
        $app = $this->app();
        $app->middleware([VerifyCsrfToken::class]);
        $app->router()->post('/notes', fn (): string => 'saved');

        $response = $app->handle($this->ajax('POST', '/notes', session: new Session([])));

        $this->assertSame(419, $response->status());
    }

    #[Test]
    public function a_wrong_header_token_is_rejected(): void
    {
        $session = new Session([]);
        $session->csrfToken();

        $app = $this->app();
        $app->middleware([VerifyCsrfToken::class]);
        $app->router()->post('/notes', fn (): string => 'saved');

        $response = $app->handle(Request::create(
            'POST',
            '/notes',
            headers: ['x-requested-with' => 'XMLHttpRequest', 'x-csrf-token' => str_repeat('a', 64)],
            session: $session,
        ));

        $this->assertSame(419, $response->status());
    }

    // --- validation over AJAX --------------------------------------------

    #[Test]
    public function validation_failures_come_back_as_422_json_for_the_script_to_render(): void
    {
        $app = $this->app();
        $app->router()->post('/notes', function (Request $request, \Phpvin\Validation\Validator $v): never {
            $v->validate($request->all(), ['body' => 'required|max:200']);

            throw new LogicException('unreachable');
        });

        $response = $app->handle($this->ajax('POST', '/notes', session: new Session([])));
        $payload = json_decode($response->body(), true);

        $this->assertSame(422, $response->status());
        $this->assertSame(['Body is required.'], $payload['errors']['body']);
    }

    // --- the bundled script ----------------------------------------------

    #[Test]
    public function the_bundled_script_is_served_with_a_javascript_content_type(): void
    {
        $app = $this->app();
        Assets::register($app->router());

        $response = $app->handle(Request::create('GET', Assets::SCRIPT_URI));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('javascript', (string) $response->getHeader('content-type'));
        $this->assertStringContainsString('phpvin.js', $response->body());
    }

    #[Test]
    public function the_script_route_can_be_built_by_name(): void
    {
        $app = $this->app();
        Assets::register($app->router());

        $urls = $app->container()->get(\Phpvin\Routing\UrlGenerator::class);

        $this->assertSame(Assets::SCRIPT_URI, $urls->route('phpvin.script'));
    }

    #[Test]
    public function an_unchanged_script_answers_304(): void
    {
        $app = $this->app();
        Assets::register($app->router());

        $first = $app->handle(Request::create('GET', Assets::SCRIPT_URI));
        $etag = (string) $first->getHeader('etag');

        $second = $app->handle(Request::create(
            'GET',
            Assets::SCRIPT_URI,
            headers: ['if-none-match' => $etag],
        ));

        $this->assertNotSame('', $etag);
        $this->assertSame(304, $second->status());
        $this->assertSame('', $second->body());
    }

    #[Test]
    public function the_script_can_be_published_into_a_public_directory(): void
    {
        $target = sys_get_temp_dir() . '/phpvin-assets-' . getmypid();

        $written = Assets::publish($target);

        $this->assertFileExists($written);
        $this->assertStringContainsString('phpvin.js', (string) file_get_contents($written));

        unlink($written);
        rmdir($target);
    }
}
