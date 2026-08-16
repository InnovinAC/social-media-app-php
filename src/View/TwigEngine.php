<?php

declare(strict_types=1);

namespace Phpvin\View;

use RuntimeException;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig templates, auto-escaped, `.twig` extension.
 *
 * Twig is an optional dependency: install it with `composer require twig/twig`
 * if you want this engine.
 */
final class TwigEngine implements Engine, SupportsFunctions
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly Environment $twig) {}

    public static function create(string $path, bool $debug = false, string|false $cachePath = false): self
    {
        if (! class_exists(Environment::class)) {
            throw new RuntimeException(
                'The Twig engine needs twig/twig. Run `composer require twig/twig`, '
                . "or set the view engine to 'php' or 'html'.",
            );
        }

        $twig = new Environment(new FilesystemLoader($path), [
            'cache' => $cachePath,
            'debug' => $debug,
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }

        return new self($twig);
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($this->normalise($template), [...$this->shared, ...$data]);
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($this->normalise($template));
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function addFunction(string $name, callable $callable): void
    {
        $this->twig->addFunction(new TwigFunction($name, $callable(...)));
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    private function normalise(string $template): string
    {
        return str_ends_with($template, '.twig') ? $template : "$template.twig";
    }
}
