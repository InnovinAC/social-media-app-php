<?php

declare(strict_types=1);

namespace Phpvin\View;

/**
 * Static `.html` files with `{{ placeholder }}` substitution.
 *
 * There is no logic, no loops and no includes. If you need any of those, use
 * the PHP or Twig engine. This exists for the case where the markup really is
 * just markup: landing pages, a single-page app shell, email bodies.
 *
 *     <h1>Hello, {{ name }}</h1>
 *
 * Values are HTML-escaped. Wrap a key in triple braces (`{{{ body }}}`) to
 * insert it raw, and only do that with markup you produced yourself.
 */
final class HtmlEngine implements Engine
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $path) {}

    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        if ($file === null || ! is_file($file)) {
            throw new ViewNotFound("Template [$template] was not found in {$this->path}.");
        }

        return $this->substitute((string) file_get_contents($file), [...$this->shared, ...$data]);
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

    /**
     * @param array<string, mixed> $data
     */
    private function substitute(string $html, array $data): string
    {
        // Raw first, so a {{{ x }}} is not consumed by the escaping pass.
        $html = (string) preg_replace_callback(
            '/\{\{\{\s*(\w+)\s*\}\}\}/',
            static fn (array $m): string => (string) ($data[$m[1]] ?? ''),
            $html,
        );

        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static fn (array $m): string => htmlspecialchars(
                (string) ($data[$m[1]] ?? ''),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
            $html,
        );
    }

    private function resolve(string $template): ?string
    {
        return TemplatePath::resolve($this->path, $template, '.html');
    }
}
