<?php

declare(strict_types=1);

namespace Phpvin\Events;

use Closure;
use InvalidArgumentException;
use Phpvin\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A small PSR-14 dispatcher.
 *
 * Events are objects and listeners are keyed by class name, so there are no
 * magic strings to typo and your editor can find every listener for an event.
 *
 *     $events->listen(UserRegistered::class, SendWelcomeEmail::class);
 *     $events->listen(UserRegistered::class, fn (UserRegistered $e) => ...);
 *
 *     $events->dispatch(new UserRegistered($user));
 *
 * Listeners run in the order they were registered. A listener given as a class
 * string is built by the container when the event fires, not before, so
 * registering one costs nothing.
 */
final class Dispatcher implements EventDispatcherInterface, ListenerProviderInterface
{
    /** @var array<class-string, list<Closure|class-string>> */
    private array $listeners = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param class-string                    $event
     * @param Closure|class-string            $listener A closure, or a class
     *                                                  with an __invoke method.
     */
    public function listen(string $event, Closure|string $listener): self
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    /**
     * Every listener for an event, including those registered against its
     * parent classes and interfaces.
     *
     * @param  object $event
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $type => $listeners) {
            if (! $event instanceof $type) {
                continue;
            }

            foreach ($listeners as $listener) {
                yield $this->toCallable($listener);
            }
        }
    }

    /**
     * @template T of object
     * @param  T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        foreach ($this->getListenersForEvent($event) as $listener) {
            // PSR-14: a stoppable event that has been stopped ends the chain,
            // checked before each listener rather than only at the end.
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * Whether anything is listening for this event type.
     */
    public function hasListeners(string $event): bool
    {
        foreach ($this->listeners as $type => $listeners) {
            if (($type === $event || is_subclass_of($event, $type)) && $listeners !== []) {
                return true;
            }
        }

        return false;
    }

    private function toCallable(Closure|string $listener): callable
    {
        if ($listener instanceof Closure) {
            return $listener;
        }

        $instance = $this->container->get($listener);

        if (! is_callable($instance)) {
            throw new InvalidArgumentException(sprintf(
                'The listener [%s] must be callable. Give it an __invoke method.',
                $listener,
            ));
        }

        return $instance;
    }
}
