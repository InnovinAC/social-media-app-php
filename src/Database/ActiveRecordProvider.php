<?php

declare(strict_types=1);

namespace Phpvin\Database;

use Phpvin\Application;
use Phpvin\Container\Container;
use Phpvin\ServiceProvider;

/**
 * Registers the bundled Active Record layer.
 *
 * Listed in the default `providers` config. Remove it (or replace it with a
 * provider of your own) and the framework carries no ORM at all.
 */
final class ActiveRecordProvider implements ServiceProvider
{
    public function register(Container $container, Application $app): void
    {
        $config = $app->config('database');

        if (! is_array($config) || $config === []) {
            return;
        }

        $container->singleton(Connection::class, static fn (): Connection => Connection::fromConfig($config));

        // The connection is lazy, so this hands models something to reach for
        // without opening a socket.
        Model::useConnection($container->get(Connection::class));

        $container->singleton(Migrator::class, static fn (Container $c): Migrator => new Migrator(
            $c->get(Connection::class),
            (string) $app->config('database.migrations', $app->basePath('database/migrations')),
        ));
    }
}
