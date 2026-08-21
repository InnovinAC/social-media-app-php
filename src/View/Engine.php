<?php

declare(strict_types=1);

namespace Phpvin\View;

/**
 * A template engine.
 *
 * The framework ships three implementations (Twig, plain PHP, and static
 * HTML), and nothing in the core depends on which one you pick. Write your own
 * by implementing these three methods.
 */
interface Engine
{
    /**
     * Render a template.
     *
     * Implementations must not resolve a name to a file outside their own
     * template root. Applications build template names out of URL segments,
     * so the name is untrusted input, and an engine that follows `..` out of
     * its directory turns a slug into arbitrary file disclosure. The bundled
     * engines all use TemplatePath for this; Twig's loader does it itself.
     *
     * @param array<string, mixed> $data
     *
     * @throws ViewNotFound when the template does not exist, or would resolve
     *                      outside the template root
     */
    public function render(string $template, array $data = []): string;

    /**
     * False for a template that does not exist, and for any name that would
     * escape the template root.
     */
    public function exists(string $template): bool;

    /**
     * Make a value available to every template rendered by this engine.
     */
    public function share(string $key, mixed $value): void;
}
