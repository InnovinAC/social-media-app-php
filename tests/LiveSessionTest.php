<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\Session;

/**
 * Session against PHP's real session handler.
 *
 * Everywhere else the tests pass a detached array, which is fast and keeps
 * them isolated, but it also means the branches that call session_start(),
 * session_regenerate_id() and session_destroy() were never executed. Those are
 * the ones production runs, and session fixation is exactly the bug they exist
 * to prevent.
 *
 * Each test gets its own process because a session id cannot be un-started.
 */
final class LiveSessionTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function start_opens_a_real_php_session(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());

        (new Session())->start();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotSame('', session_id());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function the_session_cookie_is_hardened_by_default(): void
    {
        (new Session())->start();

        $params = session_get_cookie_params();

        // A session cookie readable from JavaScript is one XSS away from a
        // stolen account, and one sent cross-site is a CSRF waiting to happen.
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame('/', $params['path']);

        // Not secure by default: a cookie marked secure is never sent over
        // plain HTTP, so a default of true would silently break every local
        // development setup. It is opt-in via config once TLS is real.
        $this->assertFalse($params['secure']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function the_session_cookie_settings_can_be_overridden(): void
    {
        $session = new Session();
        $session->useCookieOptions(['secure' => true, 'samesite' => 'Strict']);
        $session->start();

        $params = session_get_cookie_params();

        $this->assertTrue($params['secure']);
        $this->assertSame('Strict', $params['samesite']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function values_survive_in_the_real_session_superglobal(): void
    {
        $session = new Session();
        $session->start();
        $session->put('user_id', 7);

        $this->assertSame(7, $_SESSION['user_id'], 'written through to the real session');
        $this->assertSame(7, $session->get('user_id'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function starting_an_already_active_session_is_harmless(): void
    {
        $session = new Session();
        $session->start();
        $id = session_id();

        $session->start();

        $this->assertSame($id, session_id(), 'the id did not change');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_session_started_elsewhere_is_adopted_rather_than_restarted(): void
    {
        session_start();
        $id = session_id();
        $_SESSION['set_before'] = 'kept';

        (new Session())->start();

        $this->assertSame($id, session_id());
        $this->assertSame('kept', $_SESSION['set_before']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regenerate_issues_a_new_id_and_keeps_the_data(): void
    {
        $session = new Session();
        $session->start();
        $session->put('user_id', 7);

        $before = session_id();
        $session->regenerate();
        $after = session_id();

        // The whole point: a token captured before sign-in is worthless after.
        $this->assertNotSame($before, $after, 'the session id changed');
        $this->assertSame(7, $session->get('user_id'), 'the data came with it');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regenerating_without_an_active_session_does_nothing(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status());

        (new Session())->regenerate();

        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no session was conjured into existence');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function destroy_empties_the_session_and_closes_it(): void
    {
        $session = new Session();
        $session->start();
        $session->put('user_id', 7);

        $session->destroy();

        $this->assertSame([], $_SESSION);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function destroying_without_an_active_session_still_clears_the_data(): void
    {
        $_SESSION = ['left' => 'over'];

        (new Session())->destroy();

        $this->assertSame([], $_SESSION);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function the_csrf_token_persists_in_a_real_session(): void
    {
        $session = new Session();
        $session->start();

        $token = $session->csrfToken();

        $this->assertSame($token, $_SESSION['_csrf_token']);
        $this->assertTrue((new Session())->verifyCsrf($token), 'a fresh wrapper sees the same token');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function calling_start_twice_does_not_consume_the_flash_bag(): void
    {
        $session = new Session();
        $session->start();
        $session->flash('status', 'saved');

        // The second start() must be a no-op. If it aged the bag again the
        // message would be gone before the page that shows it renders.
        $session->start();

        $next = new Session();
        $next->start();

        $this->assertSame('saved', $next->flashed('status'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function regenerate_destroys_the_old_session_rather_than_leaving_it_valid(): void
    {
        $session = new Session();
        $session->start();
        $session->put('user_id', 7);

        $oldId = session_id();
        $session->regenerate();

        // Reopen the id an attacker would be holding. If the old session were
        // left behind, this would still be signed in, which is precisely the
        // fixation attack regenerating is meant to close.
        session_write_close();
        session_id($oldId);
        session_start();

        $this->assertArrayNotHasKey('user_id', $_SESSION, 'the old session was destroyed');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function flash_ages_across_a_real_session_start(): void
    {
        $session = new Session();
        $session->start();
        $session->flash('status', 'saved');

        // A second wrapper over the same live session stands in for the next
        // request picking the message up.
        $next = new Session();
        $next->start();

        $this->assertSame('saved', $next->flashed('status'));
    }
}
