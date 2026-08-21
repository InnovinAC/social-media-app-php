<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Container\Container;
use Phpvin\Events\Dispatcher;
use Psr\EventDispatcher\StoppableEventInterface;

final class EventsTest extends TestCase
{
    private Dispatcher $events;

    protected function setUp(): void
    {
        $this->events = new Dispatcher(new Container());
        RecordingListener::$seen = [];
        CountingListener::$built = 0;
    }

    #[Test]
    public function a_closure_listener_receives_the_event(): void
    {
        $seen = null;

        $this->events->listen(UserRegistered::class, function (UserRegistered $event) use (&$seen): void {
            $seen = $event->email;
        });

        $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertSame('ada@example.com', $seen);
    }

    #[Test]
    public function the_event_is_returned_so_listeners_can_answer(): void
    {
        $this->events->listen(UserRegistered::class, fn (UserRegistered $e) => $e->welcomed = true);

        $event = $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertTrue($event->welcomed);
    }

    #[Test]
    public function listeners_run_in_the_order_they_were_registered(): void
    {
        $order = [];

        $this->events->listen(UserRegistered::class, function () use (&$order): void {
            $order[] = 'first';
        });
        $this->events->listen(UserRegistered::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function an_event_with_no_listeners_dispatches_harmlessly(): void
    {
        $event = $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertFalse($event->welcomed);
    }

    #[Test]
    public function a_listener_for_another_event_is_not_called(): void
    {
        $called = false;

        $this->events->listen(OrderPlaced::class, function () use (&$called): void {
            $called = true;
        });
        $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertFalse($called);
    }

    // --- class listeners ---------------------------------------------------------

    #[Test]
    public function a_class_listener_is_built_by_the_container(): void
    {
        $this->events->listen(UserRegistered::class, RecordingListener::class);

        $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertSame(['ada@example.com'], RecordingListener::$seen);
    }

    #[Test]
    public function a_class_listener_is_not_built_until_the_event_fires(): void
    {
        $this->events->listen(UserRegistered::class, CountingListener::class);

        $this->assertSame(0, CountingListener::$built, 'registering costs nothing');

        $this->events->dispatch(new UserRegistered('ada@example.com'));

        $this->assertSame(1, CountingListener::$built);
    }

    #[Test]
    public function a_listener_that_is_not_callable_is_rejected(): void
    {
        $this->events->listen(UserRegistered::class, PagesController::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__invoke');

        $this->events->dispatch(new UserRegistered('ada@example.com'));
    }

    // --- inheritance ---------------------------------------------------------------

    #[Test]
    public function a_listener_on_a_parent_class_hears_its_subclasses(): void
    {
        $seen = [];

        $this->events->listen(DomainEvent::class, function (DomainEvent $e) use (&$seen): void {
            $seen[] = $e::class;
        });

        $this->events->dispatch(new UserRegistered('ada@example.com'));
        $this->events->dispatch(new OrderPlaced());

        $this->assertSame([UserRegistered::class, OrderPlaced::class], $seen);
    }

    #[Test]
    public function a_listener_on_an_interface_hears_anything_implementing_it(): void
    {
        $called = false;

        $this->events->listen(Auditable::class, function () use (&$called): void {
            $called = true;
        });
        $this->events->dispatch(new OrderPlaced());

        $this->assertTrue($called);
    }

    // --- stopping -------------------------------------------------------------------

    #[Test]
    public function a_stopped_event_reaches_no_further_listeners(): void
    {
        $reached = [];

        $this->events->listen(Cancellable::class, function (Cancellable $e) use (&$reached): void {
            $reached[] = 'first';
            $e->stop();
        });
        $this->events->listen(Cancellable::class, function () use (&$reached): void {
            $reached[] = 'second';
        });

        $this->events->dispatch(new Cancellable());

        $this->assertSame(['first'], $reached);
    }

    #[Test]
    public function an_event_stopped_before_dispatch_reaches_nobody(): void
    {
        $called = false;

        $this->events->listen(Cancellable::class, function () use (&$called): void {
            $called = true;
        });

        $event = new Cancellable();
        $event->stop();

        // PSR-14 says the check happens before each listener, not only between
        // them, so an already-stopped event never starts.
        $this->events->dispatch($event);

        $this->assertFalse($called);
    }

    // --- introspection ----------------------------------------------------------------

    #[Test]
    public function it_reports_whether_anything_is_listening(): void
    {
        $this->assertFalse($this->events->hasListeners(UserRegistered::class));

        $this->events->listen(UserRegistered::class, fn () => null);

        $this->assertTrue($this->events->hasListeners(UserRegistered::class));
        $this->assertFalse($this->events->hasListeners(OrderPlaced::class));
    }

    #[Test]
    public function a_parent_listener_counts_as_listening_for_the_child(): void
    {
        $this->events->listen(DomainEvent::class, fn () => null);

        $this->assertTrue($this->events->hasListeners(UserRegistered::class));
    }

    #[Test]
    public function the_listeners_for_an_event_can_be_enumerated(): void
    {
        $this->events->listen(UserRegistered::class, fn () => null);
        $this->events->listen(DomainEvent::class, fn () => null);

        $listeners = iterator_to_array($this->events->getListenersForEvent(new UserRegistered('a@b.co')));

        $this->assertCount(2, $listeners, 'its own and its parent\'s');
    }
}

abstract class DomainEvent {}

interface Auditable {}

final class UserRegistered extends DomainEvent
{
    public bool $welcomed = false;

    public function __construct(public readonly string $email) {}
}

final class OrderPlaced extends DomainEvent implements Auditable {}

final class Cancellable implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class RecordingListener
{
    /** @var list<string> */
    public static array $seen = [];

    public function __invoke(UserRegistered $event): void
    {
        self::$seen[] = $event->email;
    }
}

final class CountingListener
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function __invoke(UserRegistered $event): void {}
}
