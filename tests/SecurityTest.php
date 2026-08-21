<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Container\Container;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;
use Phpvin\Middleware\Pipeline;
use Phpvin\Middleware\SecurityHeaders;
use Phpvin\Middleware\ThrottleRequests;
use Phpvin\Middleware\VerifyCsrfToken;
use Phpvin\RateLimit\RateLimiter;
use RuntimeException;

final class SecurityTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/phpvin-throttle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->storage)) {
            rmdir($this->storage);
        }
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->storage);
    }

    /**
     * @param list<mixed> $middleware
     */
    private function pipe(array $middleware, Request $request, ?Response $result = null): Response
    {
        return (new Pipeline(new Container()))
            ->send($request)
            ->through($middleware)
            ->then(fn (): Response => $result ?? Response::html('ok'));
    }

    // --- security headers -------------------------------------------------

    #[Test]
    public function the_baseline_headers_are_added(): void
    {
        $response = $this->pipe([new SecurityHeaders()], Request::create('GET', '/'));

        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeader('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeader('Referrer-Policy'));
        $this->assertSame('same-origin', $response->getHeader('Cross-Origin-Opener-Policy'));
    }

    #[Test]
    public function no_csp_is_sent_unless_one_is_configured(): void
    {
        // A policy that breaks the page is worse than none, so it is opt-in.
        $this->assertNull(
            $this->pipe([new SecurityHeaders()], Request::create('GET', '/'))
                ->getHeader('Content-Security-Policy'),
        );

        $this->assertSame(
            "default-src 'self'",
            $this->pipe([new SecurityHeaders(contentSecurityPolicy: "default-src 'self'")], Request::create('GET', '/'))
                ->getHeader('Content-Security-Policy'),
        );
    }

    #[Test]
    public function hsts_is_off_by_default_and_opt_in(): void
    {
        $this->assertNull(
            $this->pipe([new SecurityHeaders()], Request::create('GET', '/'))
                ->getHeader('Strict-Transport-Security'),
        );

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $this->pipe([new SecurityHeaders(hsts: true)], Request::create('GET', '/'))
                ->getHeader('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function a_header_the_response_already_set_is_left_alone(): void
    {
        $response = $this->pipe(
            [new SecurityHeaders()],
            Request::create('GET', '/'),
            Response::html('embeddable')->header('X-Frame-Options', 'SAMEORIGIN'),
        );

        $this->assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
    }

    #[Test]
    public function the_headers_reach_error_responses_too(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'],
            'session' => false,
            'providers' => [],
        ]);
        $app->middleware([new SecurityHeaders()]);
        $app->router()->get('/boom', function (): never {
            throw new RuntimeException('boom');
        });

        // Global middleware has to wrap routing as well as the handler,
        // otherwise every 404 and 500 ships without these headers.
        $crashed = $app->handle(Request::create('GET', '/boom'));
        $missing = $app->handle(Request::create('GET', '/nowhere'));

        $this->assertSame(500, $crashed->status());
        $this->assertSame('nosniff', $crashed->getHeader('X-Content-Type-Options'));

        $this->assertSame(404, $missing->status());
        $this->assertSame('nosniff', $missing->getHeader('X-Content-Type-Options'));
    }

    // --- header injection -------------------------------------------------

    #[Test]
    public function a_line_break_in_a_header_value_is_refused(): void
    {
        // `redirect($request->input('next'))` is ordinary code. If the value
        // can carry CRLF it stops being one header and becomes two, and the
        // second one is whatever the attacker wanted -- classically a
        // Set-Cookie that fixes the session.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line break');

        (new Response())->header('Location', "http://example.com/\r\nSet-Cookie: admin=1");
    }

    #[Test]
    public function a_bare_newline_is_refused_too(): void
    {
        // Some parsers accept LF alone, so rejecting only CRLF is not enough.
        $this->expectException(InvalidArgumentException::class);

        (new Response())->header('X-Thing', "value\nX-Injected: 1");
    }

    #[Test]
    public function a_null_byte_in_a_header_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Response())->header('X-Thing', "value\0truncated");
    }

    #[Test]
    public function a_malformed_header_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid header name');

        (new Response())->header('X Thing: injected', 'value');
    }

    #[Test]
    public function an_empty_header_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Response())->header('', 'value');
    }

    #[Test]
    public function the_constructor_validates_headers_as_well(): void
    {
        // The array form funnels through header(), so it cannot be a way in.
        $this->expectException(InvalidArgumentException::class);

        new Response('', 200, ['Location' => "/ok\r\nSet-Cookie: a=1"]);
    }

    #[Test]
    public function a_redirect_cannot_smuggle_a_second_header(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedirectResponse("/dashboard\r\nSet-Cookie: role=admin");
    }

    #[Test]
    public function ordinary_headers_are_untouched(): void
    {
        // The guard has to be invisible to every legitimate value, including
        // punctuation-heavy ones like CSP and Content-Disposition.
        $response = (new Response())
            ->header('Content-Security-Policy', "default-src 'self'; img-src * data:")
            ->header('Content-Disposition', 'attachment; filename="q1 report.pdf"')
            ->header('X-Custom_Header', 'a|b~c')
            ->header('Cache-Control', 'no-store, max-age=0');

        $this->assertSame("default-src 'self'; img-src * data:", $response->getHeader('Content-Security-Policy'));
        $this->assertSame('attachment; filename="q1 report.pdf"', $response->getHeader('Content-Disposition'));
        $this->assertSame('a|b~c', $response->getHeader('X-Custom_Header'));
        $this->assertSame('no-store, max-age=0', $response->getHeader('Cache-Control'));
    }

    #[Test]
    public function a_tab_is_allowed_because_folded_values_are_legal(): void
    {
        $response = (new Response())->header('X-Thing', "a\tb");

        $this->assertSame("a\tb", $response->getHeader('X-Thing'));
    }

    // --- rate limiter -----------------------------------------------------

    #[Test]
    public function it_counts_attempts_against_a_key(): void
    {
        $limiter = $this->limiter();

        $this->assertSame(0, $limiter->attempts('a'));
        $this->assertSame(1, $limiter->hit('a'));
        $this->assertSame(2, $limiter->hit('a'));
        $this->assertSame(2, $limiter->attempts('a'));
    }

    #[Test]
    public function keys_are_counted_separately(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('a');
        $limiter->hit('a');
        $limiter->hit('b');

        $this->assertSame(2, $limiter->attempts('a'));
        $this->assertSame(1, $limiter->attempts('b'));
    }

    #[Test]
    public function it_reports_when_the_ceiling_is_reached(): void
    {
        $limiter = $this->limiter();

        $limiter->hit('a');
        $this->assertFalse($limiter->tooManyAttempts('a', 2));

        $limiter->hit('a');
        $this->assertTrue($limiter->tooManyAttempts('a', 2));
        $this->assertSame(0, $limiter->remaining('a', 2));
    }

    #[Test]
    public function clearing_resets_the_counter(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('a');
        $limiter->clear('a');

        $this->assertSame(0, $limiter->attempts('a'));
    }

    #[Test]
    public function the_window_expires(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('a', decaySeconds: -1);

        $this->assertSame(0, $limiter->attempts('a'), 'an elapsed window counts as zero');
    }

    #[Test]
    public function a_key_never_lands_on_disk_in_readable_form(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('login|ada@example.com|127.0.0.1');

        $names = array_map('basename', glob($this->storage . '/*') ?: []);

        $this->assertCount(1, $names);
        $this->assertStringNotContainsString('ada@example.com', $names[0]);
    }

    // --- throttle middleware ----------------------------------------------

    #[Test]
    public function requests_under_the_limit_pass_and_report_what_is_left(): void
    {
        $throttle = new ThrottleRequests($this->limiter(), maxAttempts: 3, decaySeconds: 60);

        $response = $this->pipe([$throttle], Request::create('POST', '/login'));

        $this->assertSame(200, $response->status());
        $this->assertSame('3', $response->getHeader('X-RateLimit-Limit'));
        $this->assertSame('2', $response->getHeader('X-RateLimit-Remaining'));
    }

    #[Test]
    public function the_request_over_the_limit_gets_429_with_retry_after(): void
    {
        $throttle = new ThrottleRequests($this->limiter(), maxAttempts: 2, decaySeconds: 60);
        $request = Request::create('POST', '/login');

        $this->pipe([$throttle], $request);
        $this->pipe([$throttle], $request);
        $blocked = $this->pipe([$throttle], $request);

        $this->assertSame(429, $blocked->status());
        $this->assertNotNull($blocked->getHeader('Retry-After'));
        $this->assertStringContainsString('Too many attempts', $blocked->body());
    }

    #[Test]
    public function a_blocked_request_never_reaches_the_handler(): void
    {
        $throttle = new ThrottleRequests($this->limiter(), maxAttempts: 1, decaySeconds: 60);
        $request = Request::create('POST', '/login');
        $reached = 0;

        $handler = function () use (&$reached): Response {
            $reached++;

            return Response::html('handled');
        };

        foreach (range(1, 3) as $ignored) {
            (new Pipeline(new Container()))->send($request)->through([$throttle])->then($handler);
        }

        $this->assertSame(1, $reached);
    }

    #[Test]
    public function a_custom_key_throttles_per_account_rather_than_per_address(): void
    {
        $throttle = new ThrottleRequests(
            $this->limiter(),
            maxAttempts: 1,
            decaySeconds: 60,
            resolveKey: fn (Request $r): string => 'login|' . $r->post('email'),
        );

        $ada = Request::create('POST', '/login', body: ['email' => 'ada@example.com']);
        $grace = Request::create('POST', '/login', body: ['email' => 'grace@example.com']);

        $this->pipe([$throttle], $ada);

        $this->assertSame(429, $this->pipe([$throttle], $ada)->status());
        $this->assertSame(200, $this->pipe([$throttle], $grace)->status(), 'a different account is unaffected');
    }

    // --- CSRF exemptions --------------------------------------------------

    #[Test]
    public function an_exact_path_can_be_exempted(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'], 'session' => false, 'providers' => [],
        ]);
        $app->middleware([new VerifyCsrfToken(['/webhooks/stripe'])]);
        $app->router()->post('/webhooks/stripe', fn (): string => 'received');
        $app->router()->post('/webhooks/other', fn (): string => 'received');

        $exempt = $app->handle(Request::create('POST', '/webhooks/stripe', session: new Session([])));
        $guarded = $app->handle(Request::create('POST', '/webhooks/other', session: new Session([])));

        $this->assertSame('received', $exempt->body(), 'the exact match is exempt');
        $this->assertSame(419, $guarded->status(), 'nothing else is');
    }

    #[Test]
    public function a_prefix_only_exempts_when_it_ends_in_a_star(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'], 'session' => false, 'providers' => [],
        ]);

        // No trailing star, so this must behave as an exact match and not
        // quietly exempt everything underneath it.
        $app->middleware([new VerifyCsrfToken(['/api'])]);
        $app->router()->post('/api/orders', fn (): string => 'created');

        $this->assertSame(
            419,
            $app->handle(Request::create('POST', '/api/orders', session: new Session([])))->status(),
        );
    }

    #[Test]
    public function a_wildcard_does_not_exempt_an_unrelated_path(): void
    {
        $app = new Application(__DIR__ . '/fixtures', [
            'views' => ['engine' => 'none'], 'session' => false, 'providers' => [],
        ]);
        $app->middleware([new VerifyCsrfToken(['/webhooks/*'])]);
        $app->router()->post('/admin/delete', fn (): string => 'deleted');

        $this->assertSame(
            419,
            $app->handle(Request::create('POST', '/admin/delete', session: new Session([])))->status(),
        );
    }

    // --- rate limiter edges -----------------------------------------------

    #[Test]
    public function a_window_that_expires_exactly_now_counts_as_elapsed(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('boundary', decaySeconds: 0);

        // expires == now must read as elapsed, not as one second remaining.
        $this->assertSame(0, $limiter->attempts('boundary'));
        $this->assertFalse($limiter->tooManyAttempts('boundary', 1));
    }

    #[Test]
    public function a_hit_on_an_exactly_expired_window_starts_a_new_one(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('edge', decaySeconds: 60);
        $limiter->hit('edge', decaySeconds: 60);

        // Rewritten by hand rather than by sleeping, so the boundary is exact:
        // expires == now must count as elapsed, not as still open.
        foreach (glob($this->storage . '/*') ?: [] as $file) {
            file_put_contents($file, json_encode(['count' => 2, 'expires' => time()]));
        }

        $this->assertSame(1, $limiter->hit('edge', decaySeconds: 60), 'the counter restarted');
    }

    #[Test]
    public function availability_is_zero_when_nothing_is_recorded(): void
    {
        $this->assertSame(0, $this->limiter()->availableIn('never-hit'));
    }

    #[Test]
    public function availability_counts_down_while_a_window_is_open(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('open', decaySeconds: 60);

        $this->assertGreaterThan(0, $limiter->availableIn('open'));
        $this->assertLessThanOrEqual(60, $limiter->availableIn('open'));
    }

    #[Test]
    public function a_corrupt_record_is_treated_as_no_record(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('victim');

        // A half-written file after a crash must not throw, and must not be
        // read as an enormous attempt count that locks somebody out forever.
        foreach (glob($this->storage . '/*') ?: [] as $file) {
            file_put_contents($file, '{"count": ');
        }

        $this->assertSame(0, $limiter->attempts('victim'));
        $this->assertSame(1, $limiter->hit('victim'), 'it starts a fresh window');
    }

    #[Test]
    public function a_record_missing_its_fields_is_treated_as_no_record(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('partial');

        foreach (glob($this->storage . '/*') ?: [] as $file) {
            file_put_contents($file, '{"count": 9999}');
        }

        $this->assertSame(0, $limiter->attempts('partial'));
    }

    #[Test]
    public function an_api_client_gets_a_json_429(): void
    {
        $throttle = new ThrottleRequests($this->limiter(), maxAttempts: 1, decaySeconds: 60);
        $request = Request::create('POST', '/api/login', headers: ['accept' => 'application/json']);

        $this->pipe([$throttle], $request);
        $blocked = $this->pipe([$throttle], $request);

        $this->assertSame(429, $blocked->status());
        $this->assertSame(429, json_decode($blocked->body(), true)['status']);
    }
}
