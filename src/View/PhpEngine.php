<?php

declare(strict_types=1);

namespace Phpvin\View;

use Closure;
use Throwable;

/**
 * Plain PHP templates: no compiler, no cache, no new syntax to learn.
 *
 *     <!-- resources/views/posts/show.php -->
 *     <?php $this->layout('layout', ['title' => $post['title']]) ?>
 *     <h1><?= $e($post['title']) ?></h1>
 *
 * Inside a template:
 *   $e(...)                  escape for HTML (always use it on data)
 *   $this->render(...)       render a partial
 *   $this->layout(...)       wrap this template's output in a layout
 *   $this->section()         inside a layout, the wrapped output
 *
 * Registered functions (see addFunction) are available as local closures, so
 * `route('home')` in a Twig template is `$route('home')` here.
 */
final class PhpEngine implements Engine, SupportsFunctions
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var array<string, callable> */
    private array $functions = [];

    /** @var list<array{template: string, data: array<string, mixed>}> */
    private array $layoutStack = [];

    private string $section = '';

    public function __construct(private readonly string $path) {}

    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        if ($file === null || ! is_file($file)) {
            throw new ViewNotFound("Template [$template] was not found in {$this->path}.");
        }

        $layoutDepth = count($this->layoutStack);
        $output = $this->evaluate($file, [...$this->shared, ...$data]);

        // If the template asked for a layout, render that layout with this
        // template's output available as $this->section().
        if (count($this->layoutStack) > $layoutDepth) {
            $layout = array_pop($this->layoutStack);
            $previousSection = $this->section;
            $this->section = $output;

            try {
                $output = $this->render($layout['template'], $layout['data']);
            } finally {
                $this->section = $previousSection;
            }
        }

        return $output;
    }

    public function exists(string $template): bool
    {
        $file = $this->resolve($template);

        return $file !== null && is_file($file);
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function addFunction(string $name, callable $callable): void
    {
        $this->functions[$name] = $callable;
    }

    /**
     * Called from inside a template to wrap its output in a layout.
     *
     * @param array<string, mixed> $data
     */
    public function layout(string $template, array $data = []): void
    {
        $this->layoutStack[] = ['template' => $template, 'data' => $data];
    }

    /**
     * Called from inside a layout to emit the wrapped template's output.
     */
    public function section(): string
    {
        return $this->section;
    }

    /**
     * Escape a value for HTML. Available in templates as $e.
     */
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $__data
     */
    private function evaluate(string $__file, array $__data): string
    {
        $e = $this->escape(...);

        // Registered functions become local closures: route(), asset(), ...
        extract(array_map(static fn (callable $fn): Closure => $fn(...), $this->functions), EXTR_SKIP);
        extract($__data, EXTR_SKIP);

        ob_start();

        try {
            include $__file;
        } catch (Throwable $throwable) {
            // Named $throwable, not $e: $e is the escape helper the template
            // is using, and catch would clobber it.
            ob_end_clean();

            throw $throwable;
        }

        return (string) ob_get_clean();
    }

    private function resolve(string $template): ?string
    {
        return TemplatePath::resolve($this->path, $template, '.php');
    }
}
