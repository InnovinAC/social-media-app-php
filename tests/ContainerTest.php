<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Container\Container;
use Phpvin\Container\ContainerException;
use Phpvin\Container\NotFoundException;

final class ContainerTest extends TestCase
{
    #[Test]
    public function it_autowires_a_class_with_no_constructor(): void
    {
        $container = new Container();

        $this->assertInstanceOf(Logger::class, $container->get(Logger::class));
    }

    #[Test]
    public function it_autowires_nested_constructor_dependencies(): void
    {
        $container = new Container();
        $service = $container->get(ReportService::class);

        $this->assertInstanceOf(ReportService::class, $service);
        $this->assertInstanceOf(Logger::class, $service->logger);
    }

    #[Test]
    public function autowired_classes_are_not_shared_unless_asked_for(): void
    {
        $container = new Container();

        $this->assertNotSame($container->get(Logger::class), $container->get(Logger::class));
    }

    #[Test]
    public function singletons_return_the_same_instance(): void
    {
        $container = new Container();
        $container->singleton(Logger::class);

        $this->assertSame($container->get(Logger::class), $container->get(Logger::class));
    }

    #[Test]
    public function it_binds_an_interface_to_a_concrete_class(): void
    {
        $container = new Container();
        $container->bind(Greets::class, PoliteGreeter::class);

        $this->assertInstanceOf(PoliteGreeter::class, $container->get(Greets::class));
    }

    #[Test]
    public function it_injects_a_bound_interface_into_a_constructor(): void
    {
        $container = new Container();
        $container->bind(Greets::class, PoliteGreeter::class);

        $this->assertSame('Hello, ada.', $container->get(Welcomer::class)->welcome('ada'));
    }

    #[Test]
    public function registered_instances_are_returned_as_is(): void
    {
        $container = new Container();
        $logger = new Logger();
        $container->instance(Logger::class, $logger);

        $this->assertSame($logger, $container->get(Logger::class));
    }

    #[Test]
    public function rebinding_discards_the_memoised_instance(): void
    {
        $container = new Container();
        $container->singleton(Logger::class);
        $first = $container->get(Logger::class);

        $container->singleton(Logger::class);

        $this->assertNotSame($first, $container->get(Logger::class));
    }

    #[Test]
    public function it_reports_a_missing_binding(): void
    {
        $this->expectException(NotFoundException::class);

        (new Container())->get('App\\Nope');
    }

    #[Test]
    public function it_refuses_to_instantiate_an_unbound_interface(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('interface or abstract class');

        (new Container())->get(Greets::class);
    }

    #[Test]
    public function it_detects_a_circular_dependency_instead_of_recursing_forever(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        (new Container())->get(ChickenService::class);
    }

    #[Test]
    public function it_falls_back_to_a_default_value_for_unresolvable_scalars(): void
    {
        $container = new Container();

        $this->assertSame(3, $container->get(RetryPolicy::class)->attempts);
    }

    #[Test]
    public function it_explains_which_parameter_it_could_not_resolve(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$dsn');

        (new Container())->get(NeedsAScalar::class);
    }

    #[Test]
    public function it_calls_a_closure_with_injected_dependencies(): void
    {
        $container = new Container();

        $result = $container->call(fn (Logger $logger, string $name) => $name . ':' . $logger::class, [
            'name' => 'run',
        ]);

        $this->assertSame('run:' . Logger::class, $result);
    }

    #[Test]
    public function it_calls_a_method_on_a_resolved_object(): void
    {
        $container = new Container();
        $container->bind(Greets::class, PoliteGreeter::class);

        $this->assertSame(
            'Hello, grace.',
            $container->call([Welcomer::class, 'welcome'], ['name' => 'grace']),
        );
    }

    #[Test]
    public function it_casts_string_overrides_to_the_declared_scalar_type(): void
    {
        $container = new Container();

        // Route parameters always arrive as strings; a controller asking for
        // int must not blow up under strict_types.
        $this->assertSame(42, $container->call(fn (int $id) => $id, ['id' => '42']));
        $this->assertSame(1.5, $container->call(fn (float $n) => $n, ['n' => '1.5']));
        $this->assertTrue($container->call(fn (bool $flag) => $flag, ['flag' => 'true']));
    }

    #[Test]
    public function it_leaves_non_numeric_strings_alone_when_casting(): void
    {
        $container = new Container();

        $this->assertSame('abc', $container->call(fn (string $slug) => $slug, ['slug' => 'abc']));
    }

    #[Test]
    public function has_reports_bindings_and_autowirable_classes(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(Logger::class));
        $this->assertFalse($container->has('App\\Nope'));
    }
}

// --- fixtures -------------------------------------------------------------

class Logger {}

class ReportService
{
    public function __construct(public Logger $logger) {}
}

interface Greets
{
    public function greet(string $name): string;
}

class PoliteGreeter implements Greets
{
    public function greet(string $name): string
    {
        return "Hello, $name.";
    }
}

class Welcomer
{
    public function __construct(private Greets $greeter) {}

    public function welcome(string $name): string
    {
        return $this->greeter->greet($name);
    }
}

class RetryPolicy
{
    public function __construct(public int $attempts = 3) {}
}

class NeedsAScalar
{
    public function __construct(public string $dsn) {}
}

class ChickenService
{
    public function __construct(public EggService $egg) {}
}

class EggService
{
    public function __construct(public ChickenService $chicken) {}
}
