<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\View\Engine;
use Phpvin\View\HtmlEngine;
use Phpvin\View\PhpEngine;
use Phpvin\View\TwigEngine;
use Phpvin\View\ViewFactory;
use Phpvin\View\ViewNotFound;
use RuntimeException;

final class ViewEngineTest extends TestCase
{
    private const VIEWS = __DIR__ . '/fixtures/views';

    // --- Twig -------------------------------------------------------------

    #[Test]
    public function the_twig_engine_renders_and_escapes(): void
    {
        $engine = TwigEngine::create(self::VIEWS);
        $engine->addFunction('route', fn (string $name, array $p = []): string => '/greet/' . ($p['name'] ?? ''));

        $html = $engine->render('greeting', ['name' => '<script>']);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // --- Plain PHP --------------------------------------------------------

    #[Test]
    public function the_php_engine_renders_a_template(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $engine->addFunction('route', fn (string $name, array $p = []): string => '/greet/' . ($p['name'] ?? ''));

        $html = $engine->render('greeting', ['name' => 'grace']);

        $this->assertStringContainsString('<h1>Hello, grace</h1>', $html);
    }

    #[Test]
    public function the_php_engine_wraps_a_template_in_its_layout(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $engine->addFunction('route', fn (): string => '#');

        $html = $engine->render('greeting', ['name' => 'grace']);

        $this->assertStringContainsString('<title>Greeting</title>', $html);
        $this->assertStringContainsString('<main>', $html);
        $this->assertStringContainsString('<h1>Hello, grace</h1>', $html);
    }

    #[Test]
    public function the_php_engine_escapes_through_the_e_helper(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $engine->addFunction('route', fn (): string => '#');

        $html = $engine->render('greeting', ['name' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function the_php_engine_exposes_registered_functions_as_closures(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $engine->addFunction('route', fn (string $name, array $p = []): string => '/greet/' . ($p['name'] ?? ''));

        $this->assertStringContainsString('href="/greet/ada"', $engine->render('greeting', ['name' => 'x']));
    }

    #[Test]
    public function the_php_engine_shares_values_across_templates(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $engine->addFunction('route', fn (): string => '#');
        $engine->share('name', 'shared');

        $this->assertStringContainsString('Hello, shared', $engine->render('greeting'));
    }

    #[Test]
    public function the_php_engine_reports_a_missing_template(): void
    {
        $this->expectException(ViewNotFound::class);

        (new PhpEngine(self::VIEWS))->render('nope');
    }

    #[Test]
    public function the_php_engine_does_not_leak_output_when_a_template_throws(): void
    {
        $engine = new PhpEngine(self::VIEWS);
        $before = ob_get_level();

        try {
            $engine->render('broken');
            $this->fail('Expected the template to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('template blew up', $e->getMessage());
        }

        $this->assertSame($before, ob_get_level(), 'The output buffer was left open.');
    }

    // --- Static HTML ------------------------------------------------------

    #[Test]
    public function the_html_engine_substitutes_and_escapes_placeholders(): void
    {
        $html = (new HtmlEngine(self::VIEWS))->render('greeting', [
            'name' => '<script>',
            'trusted' => '<b>bold</b>',
        ]);

        $this->assertStringContainsString('Hello, &lt;script&gt;', $html);
        $this->assertStringContainsString('<b>bold</b>', $html);
    }

    #[Test]
    public function the_html_engine_blanks_placeholders_with_no_value(): void
    {
        $html = (new HtmlEngine(self::VIEWS))->render('greeting');

        $this->assertStringContainsString('<h1>Hello, </h1>', $html);
    }

    #[Test]
    public function the_html_engine_cannot_take_functions(): void
    {
        $views = new ViewFactory(new HtmlEngine(self::VIEWS));

        $this->assertFalse($views->supportsFunctions());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot expose functions');

        $views->addFunction('route', fn (): string => '/');
    }

    // --- Selection --------------------------------------------------------

    #[Test]
    public function the_engine_is_chosen_by_config(): void
    {
        $this->assertInstanceOf(
            TwigEngine::class,
            ViewFactory::fromConfig(['engine' => 'twig', 'path' => self::VIEWS])?->engine(),
        );
        $this->assertInstanceOf(
            PhpEngine::class,
            ViewFactory::fromConfig(['engine' => 'php', 'path' => self::VIEWS])?->engine(),
        );
        $this->assertInstanceOf(
            HtmlEngine::class,
            ViewFactory::fromConfig(['engine' => 'html', 'path' => self::VIEWS])?->engine(),
        );
    }

    #[Test]
    public function engine_none_means_no_view_layer_at_all(): void
    {
        $this->assertNull(ViewFactory::fromConfig(['engine' => 'none']));
    }

    #[Test]
    public function a_custom_engine_can_be_supplied_directly(): void
    {
        $views = ViewFactory::fromConfig(['engine' => new UppercaseEngine()]);

        $this->assertSame('HELLO', $views?->render('anything', ['text' => 'hello']));
    }

    #[Test]
    public function a_custom_engine_can_be_supplied_by_closure(): void
    {
        $views = ViewFactory::fromConfig(['engine' => fn (): Engine => new UppercaseEngine()]);

        $this->assertSame('HI', $views?->render('anything', ['text' => 'hi']));
    }

    #[Test]
    public function an_unknown_engine_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown view engine [blade]');

        ViewFactory::fromConfig(['engine' => 'blade', 'path' => self::VIEWS]);
    }

    // --- End to end through the Application -------------------------------

    #[Test]
    public function an_application_can_serve_html_from_the_php_engine(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'php', 'path' => self::VIEWS],
            'providers' => [],
        ]);

        $app->router()->get('/greet/{name}', fn (ViewFactory $views, string $name): Response
            => $views->response('greeting', ['name' => $name]), as: 'greeting');

        $body = $app->handle(Request::create('GET', '/greet/grace'))->body();

        $this->assertStringContainsString('<h1>Hello, grace</h1>', $body);
        $this->assertStringContainsString('href="/greet/ada"', $body);
    }

    #[Test]
    public function an_application_can_serve_static_html(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'html', 'path' => self::VIEWS],
            'providers' => [],
        ]);

        $app->router()->get('/', fn (ViewFactory $views): Response
            => $views->response('greeting', ['name' => 'world']));

        $this->assertStringContainsString('Hello, world', $app->handle(Request::create('GET', '/'))->body());
    }
}

final class UppercaseEngine implements Engine
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function render(string $template, array $data = []): string
    {
        return strtoupper((string) ([...$this->shared, ...$data]['text'] ?? ''));
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }
}
