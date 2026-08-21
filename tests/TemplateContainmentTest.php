<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\View\Engine;
use Phpvin\View\HtmlEngine;
use Phpvin\View\PhpEngine;
use Phpvin\View\TemplatePath;
use Phpvin\View\TwigEngine;
use Phpvin\View\ViewNotFound;
use Throwable;

/**
 * A template name is untrusted input.
 *
 * Rendering "pages/$slug" for a `/page/{slug}` route is the obvious way to
 * build a CMS, which makes the name attacker-controlled. Before this was
 * closed, PhpEngine::render('../secret/evil') *executed* that file: a slug
 * became remote code execution. Every engine now refuses, identically, so
 * that changing `views.engine` in a config file cannot change what an
 * application is exposed to.
 */
final class TemplateContainmentTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpvin-contain-' . bin2hex(random_bytes(6));
        $this->outside = $this->root . '/outside';

        mkdir($this->root . '/views/nested', 0o777, true);
        mkdir($this->outside, 0o777, true);

        file_put_contents($this->root . '/views/home.php', 'home-php');
        file_put_contents($this->root . '/views/home.html', 'home-html');
        file_put_contents($this->root . '/views/home.twig', 'home-twig');
        file_put_contents($this->root . '/views/nested/deep.php', 'deep-php');

        // The prize: outside the root, and executable by the PHP engine.
        file_put_contents($this->outside . '/secret.php', '<?php echo "OWNED"; ?>');
        file_put_contents($this->outside . '/secret.html', 'SECRET');
        file_put_contents($this->outside . '/secret.twig', 'SECRET');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function views(): string
    {
        return $this->root . '/views';
    }

    /** @return array<string, array{0: string}> */
    public static function escapes(): array
    {
        return [
            'parent directory' => ['../outside/secret'],
            'doubled up' => ['../../outside/secret'],
            'hidden mid-path' => ['nested/../../outside/secret'],
            'through a directory that does not exist' => ['nope/../../outside/secret'],
            'leading slash does not rescue it' => ['/../outside/secret'],
            'backslash separators' => ['..\\outside\\secret'],
            'trailing dot dot' => ['nested/..'],
        ];
    }

    #[Test]
    #[DataProvider('escapes')]
    public function the_php_engine_refuses_to_leave_its_root(string $template): void
    {
        $this->assertRefused(new PhpEngine($this->views()), $template);
    }

    #[Test]
    #[DataProvider('escapes')]
    public function the_html_engine_refuses_to_leave_its_root(string $template): void
    {
        $this->assertRefused(new HtmlEngine($this->views()), $template);
    }

    #[Test]
    #[DataProvider('escapes')]
    public function the_twig_engine_refuses_to_leave_its_root(string $template): void
    {
        // Twig's loader has always done this. Asserted here so the three
        // engines are held to one standard rather than three.
        $engine = TwigEngine::create($this->views());

        $this->assertFalse($engine->exists($template));

        try {
            $engine->render($template);
            $this->fail("[$template] rendered from outside the template root.");
        } catch (Throwable $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    private function assertRefused(Engine $engine, string $template): void
    {
        $this->assertFalse($engine->exists($template), "exists() accepted [$template].");

        try {
            $rendered = $engine->render($template);
            $this->fail("[$template] rendered as [$rendered] from outside the root.");
        } catch (ViewNotFound $e) {
            $this->assertStringContainsString($template, $e->getMessage());
        }
    }

    #[Test]
    public function a_symlink_pointing_out_of_the_root_is_refused(): void
    {
        // The name is clean, so only resolving the real path catches this.
        symlink($this->outside . '/secret.php', $this->views() . '/link.php');

        $this->assertRefused(new PhpEngine($this->views()), 'link');
    }

    #[Test]
    public function a_null_byte_in_the_name_is_refused(): void
    {
        // "secret.php\0.html" passes an extension check in PHP and is
        // truncated by the C library that opens it.
        $this->assertNull(TemplatePath::resolve($this->views(), "../outside/secret.php\0", '.html'));
    }

    #[Test]
    public function a_root_that_does_not_exist_resolves_to_nothing(): void
    {
        $this->assertNull(TemplatePath::resolve($this->root . '/gone', 'home', '.php'));
    }

    // --- the guarantee must not cost ordinary rendering -------------------

    #[Test]
    public function legitimate_templates_still_render(): void
    {
        $this->assertSame('home-php', (new PhpEngine($this->views()))->render('home'));
        $this->assertSame('home-html', (new HtmlEngine($this->views()))->render('home'));
        $this->assertSame('deep-php', (new PhpEngine($this->views()))->render('nested/deep'));
    }

    #[Test]
    public function an_explicit_extension_is_not_doubled(): void
    {
        $this->assertSame('home-php', (new PhpEngine($this->views()))->render('home.php'));
        $this->assertSame('home-html', (new HtmlEngine($this->views()))->render('home.html'));
    }

    #[Test]
    public function a_leading_slash_is_read_as_root_relative(): void
    {
        $this->assertSame('home-php', (new PhpEngine($this->views()))->render('/home'));
    }

    #[Test]
    public function a_missing_template_is_still_an_ordinary_not_found(): void
    {
        // A name that is merely wrong and a name that is hostile both fail,
        // but the first is a typo and has to stay debuggable.
        $path = TemplatePath::resolve($this->views(), 'nope', '.php');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('/views/nope.php', $path);
        $this->assertFileDoesNotExist($path);
    }

    #[Test]
    public function exists_is_true_for_a_template_that_is_there(): void
    {
        $this->assertTrue((new PhpEngine($this->views()))->exists('home'));
        $this->assertTrue((new HtmlEngine($this->views()))->exists('home'));
    }
}
