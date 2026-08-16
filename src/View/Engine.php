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
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string;

    public function exists(string $template): bool;

    /**
     * Make a value available to every template rendered by this engine.
     */
    public function share(string $key, mixed $value): void;
}
