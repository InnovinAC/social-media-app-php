<?php

declare(strict_types=1);

namespace App\Tests;

use Phpvin\Application;
use Phpvin\Database\Connection;
use Phpvin\Database\Migrator;
use Phpvin\Database\Model;
use Phpvin\Testing\ApplicationTestCase;

/**
 * The skeleton's own tests, built on the framework's testing toolkit.
 *
 * The application is booted the same way `public/index.php` boots it, against
 * a fresh in-memory database with the real migrations applied, so a broken
 * migration fails here rather than in production.
 */
abstract class AppTestCase extends ApplicationTestCase
{
    protected Connection $db;

    protected function createApplication(): Application
    {
        $root = dirname(__DIR__);

        $this->db = Connection::sqliteInMemory();
        Model::useConnection($this->db);

        (new Migrator($this->db, $root . '/database/migrations'))->run();

        $app = new Application($root, [
            'debug' => true,
            'views' => ['engine' => 'twig', 'path' => $root . '/resources/views'],
            // The database is already wired above; the provider would replace
            // it with a file-backed one from config.php.
            'providers' => [\App\Providers\AppProvider::class],
            'rate_limit' => ['path' => sys_get_temp_dir() . '/phpvin-test-limits-' . getmypid()],
        ]);

        $app->middleware([
            \Phpvin\Middleware\SecurityHeaders::class,
            \Phpvin\Middleware\VerifyCsrfToken::class,
            \Phpvin\Middleware\UnobtrusiveJavaScript::class,
            \App\Middleware\ShareCurrentUser::class,
        ]);

        (require $root . '/routes/web.php')($app->router());

        return $app;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir() . '/phpvin-test-limits-' . getmypid() . '/*') ?: [] as $file) {
            unlink($file);
        }

        Model::useConnection(null);

        parent::tearDown();
    }

    /**
     * Register through the real endpoint, so a test signs in the way a person
     * would rather than by poking the session.
     */
    protected function registerAndSignIn(string $email = 'ada@example.com'): void
    {
        $this->post('/register', [
            'name' => 'Ada',
            'email' => $email,
            'password' => 'correcthorsebattery',
            'password_confirmation' => 'correcthorsebattery',
        ])->assertRedirect('/dashboard');
    }
}
