<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Phpvin\Application;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Middleware\VerifyCsrfToken;
use Phpvin\Testing\ApplicationTestCase;
use Phpvin\Validation\Validator;
use RuntimeException;

/**
 * The testing toolkit, tested.
 *
 * An assertion helper that passes when it should fail is worse than no helper
 * at all, so each assertion is checked in both directions.
 */
final class TestingToolkitTest extends ApplicationTestCase
{
    protected function createApplication(): Application
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'php', 'path' => __DIR__ . '/fixtures/views'],
            'providers' => [],
        ]);

        $app->middleware([VerifyCsrfToken::class]);

        $routes = $app->router();
        $routes->get('/', fn (): string => '<p>hello &amp; welcome</p>');
        $routes->get('/json', fn (): array => ['status' => 'ok', 'items' => [['title' => 'first']]]);
        $routes->get('/missing', fn (): never => throw \Phpvin\Http\HttpException::notFound());
        $routes->get('/boom', fn (): never => throw new RuntimeException('bang'));
        $routes->get('/no-content', fn () => null);
        $routes->post('/save', fn (): Response => (new RedirectResponse('/', 303)));
        $routes->post('/flash', function (Request $request): Response {
            return (new RedirectResponse('/', 303))->with($request->session(), 'success', 'Saved.');
        });
        $routes->post('/validate', function (Request $request, Validator $validator): never {
            $validator->validate($request->all(), ['email' => 'required|email']);

            throw new LogicException('unreachable');
        });
        $routes->get('/who', fn (Request $request): array => ['user' => $request->session()->get('user_id')]);
        $routes->get('/loop', fn (): Response => new RedirectResponse('/loop', 302));
        $routes->get('/long', fn (): string => str_repeat('x', 2000));
        $routes->get('/amp', fn (): string => 'Tom &amp; Jerry');

        return $app;
    }

    private function failsWith(callable $assertion): AssertionFailedError
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            return $e;
        }

        $this->fail('Expected the assertion to fail.');
    }

    // --- requests ------------------------------------------------------------

    #[Test]
    public function a_get_request_goes_through_the_application(): void
    {
        $this->get('/')->assertOk()->assertSee('hello & welcome');
    }

    #[Test]
    public function see_escapes_by_default_and_can_be_told_not_to(): void
    {
        $this->get('/')
            ->assertSee('hello & welcome')
            ->assertSee('<p>hello &amp; welcome</p>', escape: false)
            ->assertDontSee('goodbye');
    }

    #[Test]
    public function json_helpers_read_the_body(): void
    {
        $this->get('/json')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('items.0.title', 'first')
            ->assertJsonCount(1, 'items');
    }

    #[Test]
    public function status_helpers_cover_the_common_cases(): void
    {
        $this->get('/missing')->assertNotFound();
        $this->get('/boom')->assertServerError();
        $this->get('/no-content')->assertNoContent();
        $this->get('/')->assertSuccessful();
    }

    #[Test]
    public function a_redirect_can_be_asserted_with_or_without_its_target(): void
    {
        $this->post('/save')->assertRedirect()->assertRedirect('/');
    }

    // --- the assertions actually fail ---------------------------------------

    #[Test]
    public function assert_ok_fails_on_a_404(): void
    {
        $response = $this->get('/missing');

        $this->assertStringContainsString('Got 404', $this->failsWith($response->assertOk(...))->getMessage());
    }

    #[Test]
    public function a_failure_message_includes_the_body(): void
    {
        $message = $this->failsWith($this->get('/json')->assertNotFound(...))->getMessage();

        // A bare "200 is not 404" costs a debugging session; the body is the
        // thing that tells you why.
        $this->assertStringContainsString('"status":"ok"', $message);
    }

    #[Test]
    public function assert_see_fails_when_the_text_is_absent(): void
    {
        $this->failsWith(fn () => $this->get('/')->assertSee('nowhere in the page'));
    }

    #[Test]
    public function assert_redirect_fails_on_a_200(): void
    {
        $this->failsWith($this->get('/')->assertRedirect(...));
    }

    #[Test]
    public function assert_json_path_fails_on_a_missing_path(): void
    {
        $this->failsWith(fn () => $this->get('/json')->assertJsonPath('items.5.title', 'nope'));
    }

    #[Test]
    public function assert_header_fails_when_the_header_is_missing(): void
    {
        $this->failsWith(fn () => $this->get('/')->assertHeader('X-Nope'));
    }

    // --- session behaviour ---------------------------------------------------

    #[Test]
    public function the_session_persists_between_requests(): void
    {
        $this->withSession(['user_id' => 7]);

        $this->get('/who')->assertJson(['user' => 7]);
        $this->get('/who')->assertJson(['user' => 7]);
    }

    #[Test]
    public function flashed_values_are_readable_after_the_redirect(): void
    {
        $this->post('/flash')
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Saved.');
    }

    #[Test]
    public function assert_session_has_fails_for_a_key_that_was_never_set(): void
    {
        $this->failsWith(fn () => $this->get('/')->assertSessionHas('never-set'));
    }

    // --- CSRF ----------------------------------------------------------------

    #[Test]
    public function a_post_carries_a_csrf_token_without_being_asked(): void
    {
        // Otherwise every write test would begin by fetching a page just to
        // scrape a hidden input.
        $this->post('/save')->assertRedirect('/');
    }

    #[Test]
    public function the_token_can_be_withheld_to_prove_the_guard_works(): void
    {
        $this->withoutCsrfToken()->post('/save')->assertStatus(419);
    }

    #[Test]
    public function withholding_the_token_applies_to_one_request_only(): void
    {
        $this->withoutCsrfToken()->post('/save')->assertStatus(419);
        $this->post('/save')->assertRedirect('/');
    }

    // --- validation ----------------------------------------------------------

    #[Test]
    public function validation_errors_are_found_in_a_redirect(): void
    {
        $this->post('/validate', ['email' => 'nope'])->assertValidationErrors(['email']);
    }

    #[Test]
    public function validation_errors_are_found_in_a_422(): void
    {
        $this->json('POST', '/validate', ['email' => 'nope'])
            ->assertStatus(422)
            ->assertValidationErrors(['email']);
    }

    #[Test]
    public function asserting_an_error_for_a_field_that_passed_fails(): void
    {
        $failure = $this->failsWith(
            fn () => $this->post('/validate', ['email' => 'nope'])->assertValidationErrors(['nickname']),
        );

        // The message lists what did fail, so the fix is obvious.
        $this->assertStringContainsString('email', $failure->getMessage());
    }

    #[Test]
    public function no_validation_errors_can_be_asserted_too(): void
    {
        $this->get('/')->assertNoValidationErrors();
    }

    // --- redirect following ---------------------------------------------------

    #[Test]
    public function redirects_can_be_followed_like_a_browser(): void
    {
        $this->followingRedirects()
            ->post('/save')
            ->assertOk()
            ->assertSee('hello & welcome');
    }

    #[Test]
    public function a_redirect_loop_gives_up_instead_of_hanging(): void
    {
        // The application under test is broken; the test run should say so,
        // not spin forever.
        $this->followingRedirects()->get('/loop')->assertRedirect('/loop');
    }

    #[Test]
    public function a_header_can_be_asserted_without_naming_its_value(): void
    {
        $this->get('/')->assertHeader('Content-Type');
    }

    #[Test]
    public function a_session_key_can_be_asserted_without_naming_its_value(): void
    {
        $this->post('/flash')->assertSessionHas('success');
    }

    #[Test]
    public function dont_see_escapes_its_needle_by_default(): void
    {
        // The page contains the escaped form, so the default must find it and
        // the assertion must fail.
        $this->failsWith(fn () => $this->get('/amp')->assertDontSee('Tom & Jerry'));

        // Told not to escape, the raw ampersand is genuinely absent.
        $this->get('/amp')->assertDontSee('Tom & Jerry', escape: false);
    }

    #[Test]
    public function a_long_body_is_truncated_in_the_failure_message(): void
    {
        $message = $this->failsWith(fn () => $this->get('/long')->assertNotFound())->getMessage();

        $this->assertStringContainsString('…', $message, 'the body was cut short');
        $this->assertLessThan(1000, mb_strlen($message), 'and the message stayed readable');
    }

    // --- api helper -----------------------------------------------------------

    #[Test]
    public function the_json_helper_asks_for_json(): void
    {
        // Same route, different shape, because the request said so.
        $this->json('GET', '/missing')->assertNotFound();

        $this->assertJson($this->json('GET', '/missing')->response->body());
    }
}
