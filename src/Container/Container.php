<?php

declare(strict_types=1);

namespace Phpvin\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * A small PSR-11 container with constructor autowiring.
 *
 * The rule the whole framework follows: if you need something, ask for it in
 * your constructor. There are no facades, no global helpers, and no service
 * locator to reach through. Anything reachable is reachable because it was
 * handed to you.
 */
class Container implements ContainerInterface
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var list<string> Resolution stack, used to detect cycles. */
    private array $building = [];

    /**
     * Memoised reflection lookups.
     *
     * Per-instance, not static. A process-wide cache would be faster by a
     * hair and would also be exactly the hidden global state this class exists
     * to argue against.
     *
     * @var array<string, bool>
     */
    private array $instantiable = [];

    /**
     * Bind an identifier to a factory or a concrete class name.
     *
     * A new instance is produced on every get() unless $shared is true.
     */
    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $concrete ??= $id;

        if (is_string($concrete)) {
            $class = $concrete;
            $concrete = fn (Container $c) => $c->build($class);
        }

        $this->bindings[$id] = $concrete;
        $this->shared[$id] = $shared;

        // A rebind invalidates anything already memoised under this id.
        unset($this->resolved[$id]);
    }

    /**
     * Bind an identifier that should resolve to the same instance every time.
     */
    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, shared: true);
    }

    /**
     * Register an already-constructed object.
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->resolved[$id] = $instance;
        $this->shared[$id] = true;
    }

    /**
     * True when get() would succeed. Note that an interface with no binding
     * is *not* "has": it exists, but nothing could be built from it.
     */
    public function has(string $id): bool
    {
        if (isset($this->bindings[$id]) || array_key_exists($id, $this->resolved)) {
            return true;
        }

        return $this->instantiable[$id] ??= class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * Resolve an identifier.
     *
     * @throws NotFoundException  when nothing is bound and no such class exists
     * @throws ContainerException when a dependency cannot be constructed
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (in_array($id, $this->building, true)) {
            throw ContainerException::circularDependency($id, $this->building);
        }

        $this->building[] = $id;

        try {
            if (isset($this->bindings[$id])) {
                $object = ($this->bindings[$id])($this);
            } elseif (class_exists($id) || interface_exists($id)) {
                // build() rejects interfaces and abstracts with a message that
                // says what to do about it.
                $object = $this->build($id);
            } else {
                throw NotFoundException::forId($id);
            }
        } finally {
            array_pop($this->building);
        }

        if ($this->shared[$id] ?? false) {
            $this->resolved[$id] = $object;
        }

        return $object;
    }

    /**
     * Construct a class, resolving its constructor dependencies.
     *
     * @param array<string, mixed> $overrides Values injected by parameter name,
     *                                        taking precedence over autowiring.
     */
    public function build(string $class, array $overrides = []): object
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw ContainerException::notInstantiable($class);
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs(
            $this->resolveParameters($constructor, $overrides, $class),
        );
    }

    /**
     * Invoke a callable, resolving its parameters from the container.
     *
     * @param callable|array{0: object|class-string, 1: string} $target
     * @param array<string, mixed> $overrides Values injected by parameter name.
     */
    public function call(callable|array $target, array $overrides = []): mixed
    {
        if (is_array($target)) {
            [$object, $method] = $target;

            if (is_string($object)) {
                $object = $this->get($object);
            }

            $reflection = new ReflectionMethod($object, $method);
            $context = $object::class . "::$method";

            return $reflection->invokeArgs(
                $object,
                $this->resolveParameters($reflection, $overrides, $context),
            );
        }

        $reflection = new ReflectionFunction($target(...));

        return $target(...$this->resolveParameters($reflection, $overrides, 'Closure'));
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return list<mixed>
     */
    private function resolveParameters(
        ReflectionFunctionAbstract $function,
        array $overrides,
        string $context,
    ): array {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            // Variadics are always last and collect whatever is left over,
            // which for an autowired call is nothing.
            if ($parameter->isVariadic()) {
                break;
            }

            $arguments[] = $this->resolveParameter($parameter, $overrides, $context);
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function resolveParameter(
        ReflectionParameter $parameter,
        array $overrides,
        string $context,
    ): mixed {
        $name = $parameter->getName();
        $type = $parameter->getType();

        if (array_key_exists($name, $overrides)) {
            return $this->coerce($overrides[$name], $type);
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $class = $type->getName();

            if ($this->has($class)) {
                return $this->get($class);
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            // Unresolvable, and the caller gave us no way out. Let get() throw:
            // its message names the class and says how to bind it.
            return $this->get($class);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw ContainerException::unresolvableParameter(
            $context,
            $name,
            $type instanceof ReflectionNamedType ? $type->getName() : null,
        );
    }

    /**
     * Route parameters arrive as strings because that is what a URL is made
     * of. A controller that asks for `int $id` should get an int, and this
     * file runs under strict_types, so the cast has to happen here.
     */
    private function coerce(mixed $value, ?ReflectionType $type): mixed
    {
        if (! is_string($value) || ! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value,
            default => $value,
        };
    }
}
