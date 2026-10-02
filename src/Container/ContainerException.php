<?php

declare(strict_types=1);

namespace Phpvin\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $chain
     */
    public static function circularDependency(string $id, array $chain): self
    {
        $path = implode(' -> ', [...$chain, $id]);

        return new self("Circular dependency while resolving [$id]: $path");
    }

    public static function notInstantiable(string $id): self
    {
        return new self("Cannot instantiate [$id]: it is an interface or abstract class. Bind it to a concrete implementation first.");
    }

    public static function unresolvableParameter(string $id, string $parameter, ?string $type): self
    {
        $hint = $type === null
            ? 'it has no type hint'
            : "[$type] is a built-in type with no default value";

        return new self("Cannot resolve \$$parameter for [$id]: $hint. Bind [$id] explicitly or give the parameter a default.");
    }
}
