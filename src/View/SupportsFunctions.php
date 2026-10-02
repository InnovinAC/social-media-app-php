<?php

declare(strict_types=1);

namespace Phpvin\View;

/**
 * Implemented by engines that can expose PHP callables to templates.
 *
 * Kept separate from Engine because a static-HTML engine genuinely cannot do
 * this, and silently ignoring a registered function would be worse than not
 * offering the method at all.
 */
interface SupportsFunctions
{
    public function addFunction(string $name, callable $callable): void;
}
