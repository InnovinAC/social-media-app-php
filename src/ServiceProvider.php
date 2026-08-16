<?php

declare(strict_types=1);

namespace Phpvin;

use Phpvin\Container\Container;

/**
 * A unit of optional wiring, run once at boot.
 *
 * This is how anything the core does not depend on gets plugged in. The
 * bundled Active Record layer is registered by a provider like any third-party
 * one would be, which is what makes it removable: drop
 * ActiveRecordProvider from the `providers` config, list your own, and the
 * framework never mentions the bundled ORM again.
 *
 *     final class DoctrineProvider implements ServiceProvider
 *     {
 *         public function register(Container $container, Application $app): void
 *         {
 *             $container->singleton(EntityManagerInterface::class, fn () => ...);
 *         }
 *     }
 */
interface ServiceProvider
{
    public function register(Container $container, Application $app): void;
}
