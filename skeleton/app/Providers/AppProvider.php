<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Auth;
use Phpvin\Application;
use Phpvin\Container\Container;
use Phpvin\ServiceProvider;

/**
 * Application wiring.
 *
 * Anything that should be shared for the length of a request goes here.
 * Classes the container can figure out on its own do not need registering;
 * this file is for the ones where the default (a fresh instance each time) is
 * the wrong answer.
 */
final class AppProvider implements ServiceProvider
{
    public function register(Container $container, Application $app): void
    {
        // One Auth per request, so the signed-in user is looked up once
        // however many places ask for it.
        $container->singleton(Auth::class);
    }
}
