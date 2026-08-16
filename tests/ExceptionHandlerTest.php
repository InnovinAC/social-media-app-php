<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\ExceptionHandler;
use Phpvin\Http\HttpException;
use Phpvin\Http\Request;
use Phpvin\Http\Session;
use Phpvin\Validation\ValidationException;
use Phpvin\View\Engine;
use Phpvin\View\ViewFactory;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * The code that runs when everything else has already gone wrong. It has to
 * hold up under conditions the rest of the framework never sees.
 */
final class ExceptionHandlerTest extends TestCase
{
    private function html(): Request
    {
        return Request::create('GET', '/thing', headers: ['accept' => 'text/html']);
    }

    private function json(): Request
    {
        return Request::create('GET', '/thing', headers: ['accept' => 'application/json']);
    }

    // --- status mapping ---------------------------------------------------

    #[Test]
    public function an_http_exception_keeps_its_status(): void
    {
        $response = (new ExceptionHandler())->render(HttpException::notFound(), $this->html());

        $this->assertSame(404, $response->status());
    }

    #[Test]
    public function anything_else_is_a_500(): void
    {
        $response = (new ExceptionHandler())->render(new RuntimeException('oops'), $this->html());

        $this->assertSame(500, $response->status());
    }

    // --- what reaches the client -----------------------------------------

    #[Test]
    public function a_5xx_message_is_withheld_when_debug_is_off(): void
    {
        $response = (new ExceptionHandler(debug: false))
            ->render(new RuntimeException('the db password is hunter2'), $this->html());

        $this->assertStringNotContainsString('hunter2', $response->body());
    }

    #[Test]
    public function a_5xx_message_is_shown_when_debug_is_on(): void
    {
        $response = (new ExceptionHandler(debug: true))
            ->render(new RuntimeException('the actual problem'), $this->html());

        $this->assertStringContainsString('the actual problem', $response->body());
    }

    #[Test]
    public function a_4xx_message_is_shown_because_it_describes_the_clients_mistake(): void
    {
        $response = (new ExceptionHandler(debug: false))
            ->render(HttpException::notFound('No such note.'), $this->html());

        $this->assertStringContainsString('No such note.', $response->body());
    }

    #[Test]
    public function a_stack_trace_never_reaches_the_client_with_debug_off(): void
    {
        $response = (new ExceptionHandler(debug: false))
            ->render(new RuntimeException('boom'), $this->json());

        $payload = json_decode($response->body(), true);

        $this->assertArrayNotHasKey('trace', $payload);
        $this->assertArrayNotHasKey('file', $payload);
    }

    #[Test]
    public function debug_json_carries_the_trace(): void
    {
        $response = (new ExceptionHandler(debug: true))
            ->render(new RuntimeException('boom'), $this->json());

        $this->assertArrayHasKey('trace', json_decode($response->body(), true));
    }

    // --- content negotiation ---------------------------------------------

    #[Test]
    public function a_json_client_gets_json(): void
    {
        $response = (new ExceptionHandler())->render(HttpException::notFound(), $this->json());

        $this->assertJson($response->body());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('content-type'));
    }

    #[Test]
    public function an_api_only_handler_answers_json_without_an_accept_header(): void
    {
        $response = (new ExceptionHandler(preferJson: true))
            ->render(HttpException::notFound(), Request::create('GET', '/thing'));

        $this->assertJson($response->body());
    }

    // --- views ------------------------------------------------------------

    #[Test]
    public function it_uses_an_error_template_when_one_exists(): void
    {
        $views = new ViewFactory(new StubEngine(['errors/404' => 'a custom 404 page']));

        $response = (new ExceptionHandler(views: $views))->render(HttpException::notFound(), $this->html());

        $this->assertSame('a custom 404 page', $response->body());
        $this->assertSame(404, $response->status());
    }

    #[Test]
    public function it_falls_back_to_the_built_in_page_when_no_template_exists(): void
    {
        $views = new ViewFactory(new StubEngine([]));

        $response = (new ExceptionHandler(views: $views))->render(HttpException::notFound(), $this->html());

        $this->assertStringContainsString('<!doctype html>', $response->body());
        $this->assertStringContainsString('404', $response->body());
    }

    #[Test]
    public function a_template_that_throws_does_not_take_the_error_page_down_with_it(): void
    {
        // The one failure mode that matters: the error renderer itself broken.
        $views = new ViewFactory(new StubEngine(['errors/500' => null]));

        $response = (new ExceptionHandler(views: $views))->render(new RuntimeException('original'), $this->html());

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('<!doctype html>', $response->body());
    }

    // --- validation -------------------------------------------------------

    #[Test]
    public function a_validation_failure_redirects_back_for_a_browser(): void
    {
        $session = new Session([]);
        $request = Request::create('POST', '/register', headers: ['referer' => '/register'], session: $session);

        $response = (new ExceptionHandler())
            ->render(new ValidationException(['email' => ['Required.']], ['email' => '']), $request);

        $this->assertSame(303, $response->status());
        $this->assertSame('/register', $response->getHeader('location'));
        $this->assertArrayHasKey('email', (new Session($session->all()))->flashed('errors'));
    }

    #[Test]
    public function a_validation_failure_is_422_json_when_there_is_no_session(): void
    {
        $response = (new ExceptionHandler())
            ->render(new ValidationException(['email' => ['Required.']]), Request::create('POST', '/register'));

        $this->assertSame(422, $response->status());
    }

    // --- logging ----------------------------------------------------------

    #[Test]
    public function a_500_is_logged_at_error_with_the_request_attached(): void
    {
        $logger = new RecordingLogger();

        (new ExceptionHandler(logger: $logger))->render(new RuntimeException('boom'), $this->html());

        $this->assertCount(1, $logger->lines);
        $this->assertSame('error', $logger->lines[0]['level']);
        $this->assertSame('/thing', $logger->lines[0]['context']['path']);
        $this->assertSame(500, $logger->lines[0]['context']['status']);
    }

    #[Test]
    public function a_404_is_logged_at_info_so_bot_traffic_does_not_drown_the_log(): void
    {
        $logger = new RecordingLogger();

        (new ExceptionHandler(logger: $logger))->render(HttpException::notFound(), $this->html());

        $this->assertSame('info', $logger->lines[0]['level']);
    }

    #[Test]
    public function the_handler_works_with_no_logger_at_all(): void
    {
        $response = (new ExceptionHandler())->render(new RuntimeException('boom'), $this->html());

        $this->assertSame(500, $response->status());
    }
}

/**
 * Returns canned templates. A null body means "this template throws", which is
 * how the broken-error-page case is reproduced.
 */
final class StubEngine implements Engine
{
    /** @param array<string, string|null> $templates */
    public function __construct(private array $templates) {}

    public function render(string $template, array $data = []): string
    {
        if (! array_key_exists($template, $this->templates)) {
            throw new LogicException("No template [$template].");
        }

        // A null entry stands for a template that blows up when rendered.
        return $this->templates[$template] ?? throw new RuntimeException("Template [$template] is broken.");
    }

    public function exists(string $template): bool
    {
        return array_key_exists($template, $this->templates);
    }

    public function share(string $key, mixed $value): void {}
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
