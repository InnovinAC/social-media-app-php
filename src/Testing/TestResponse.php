<?php

declare(strict_types=1);

namespace Phpvin\Testing;

use LogicException;
use PHPUnit\Framework\Assert;
use Phpvin\Http\Response;
use Phpvin\Http\Session;

/**
 * A response, wrapped in assertions.
 *
 * Every method returns $this, so a test reads as a sentence:
 *
 *     $this->post('/notes', ['body' => 'hello'])
 *         ->assertRedirect('/dashboard')
 *         ->assertSessionHas('success');
 *
 * Failures say what they got, not just that something did not match: a bare
 * "failed asserting that 500 matches 200" costs you a debugging session.
 */
final class TestResponse
{
    public function __construct(
        public readonly Response $response,
        private readonly ?Session $session = null,
    ) {}

    // --- status ------------------------------------------------------------

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->response->status(), $this->explain("Expected status $expected."));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorised(): self
    {
        return $this->assertStatus(401);
    }

    public function assertSuccessful(): self
    {
        Assert::assertTrue(
            $this->response->isSuccessful(),
            $this->explain('Expected a 2xx response.'),
        );

        return $this;
    }

    public function assertServerError(): self
    {
        Assert::assertGreaterThanOrEqual(500, $this->response->status(), $this->explain('Expected a 5xx response.'));

        return $this;
    }

    // --- redirects ----------------------------------------------------------

    public function assertRedirect(?string $to = null): self
    {
        Assert::assertTrue($this->response->isRedirect(), $this->explain('Expected a redirect.'));

        if ($to !== null) {
            Assert::assertSame($to, $this->response->getHeader('location'), 'Redirected somewhere else.');
        }

        return $this;
    }

    // --- headers -------------------------------------------------------------

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->response->getHeader($name);

        Assert::assertNotNull($actual, "The [$name] header is missing.");

        if ($value !== null) {
            Assert::assertSame($value, $actual, "The [$name] header does not match.");
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertNull($this->response->getHeader($name), "The [$name] header should not be present.");

        return $this;
    }

    // --- body ---------------------------------------------------------------

    public function assertSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? htmlspecialchars($text, ENT_QUOTES) : $text;

        Assert::assertStringContainsString($needle, $this->response->body(), $this->explain("Expected to see [$text]."));

        return $this;
    }

    public function assertDontSee(string $text, bool $escape = true): self
    {
        $needle = $escape ? htmlspecialchars($text, ENT_QUOTES) : $text;

        Assert::assertStringNotContainsString($needle, $this->response->body(), "Did not expect to see [$text].");

        return $this;
    }

    public function assertBody(string $expected): self
    {
        Assert::assertSame($expected, $this->response->body());

        return $this;
    }

    // --- JSON ----------------------------------------------------------------

    /**
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->response->body(), true);

        Assert::assertIsArray($decoded, $this->explain('The body is not JSON.'));

        return $decoded;
    }

    /**
     * Assert the response contains at least these keys and values.
     *
     * @param array<string, mixed> $expected
     */
    public function assertJson(array $expected): self
    {
        $actual = $this->json();

        foreach ($expected as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, "The JSON has no [$key].");
            Assert::assertSame($value, $actual[$key], "The JSON value for [$key] does not match.");
        }

        return $this;
    }

    /**
     * Assert a value at a dotted path: `data.0.title`.
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $value = $this->json();

        foreach (explode('.', $path) as $segment) {
            Assert::assertIsArray($value, "The JSON path [$path] runs past a leaf.");
            Assert::assertArrayHasKey($segment, $value, "The JSON has no [$path].");
            $value = $value[$segment];
        }

        Assert::assertSame($expected, $value, "The JSON value at [$path] does not match.");

        return $this;
    }

    public function assertJsonCount(int $expected, ?string $path = null): self
    {
        $value = $this->json();

        if ($path !== null) {
            foreach (explode('.', $path) as $segment) {
                Assert::assertIsArray($value);
                Assert::assertArrayHasKey($segment, $value, "The JSON has no [$path].");
                $value = $value[$segment];
            }
        }

        Assert::assertIsArray($value);
        Assert::assertCount($expected, $value);

        return $this;
    }

    // --- session -------------------------------------------------------------

    public function assertSessionHas(string $key, mixed $value = null): self
    {
        $session = $this->session();

        Assert::assertTrue($session->has($key) || $session->flashed($key) !== null, "The session has no [$key].");

        if ($value !== null) {
            $actual = $session->has($key) ? $session->get($key) : $session->flashed($key);
            Assert::assertSame($value, $actual, "The session value for [$key] does not match.");
        }

        return $this;
    }

    public function assertSessionMissing(string $key): self
    {
        Assert::assertFalse($this->session()->has($key), "The session should not have [$key].");

        return $this;
    }

    /**
     * Assert the request failed validation for these fields.
     *
     * Works for both shapes: a redirect with errors flashed, and a 422 with a
     * JSON body.
     *
     * @param list<string> $fields
     */
    public function assertValidationErrors(array $fields): self
    {
        $errors = $this->response->status() === 422
            ? ($this->json()['errors'] ?? [])
            : $this->session()->flashed('errors', []);

        Assert::assertIsArray($errors);

        foreach ($fields as $field) {
            Assert::assertArrayHasKey(
                $field,
                $errors,
                sprintf('Expected a validation error for [%s]. Got: %s', $field, implode(', ', array_keys($errors))),
            );
        }

        return $this;
    }

    public function assertNoValidationErrors(): self
    {
        $errors = $this->response->status() === 422
            ? ($this->json()['errors'] ?? [])
            : $this->session()->flashed('errors', []);

        Assert::assertSame([], $errors, 'Expected no validation errors.');

        return $this;
    }

    private function session(): Session
    {
        return $this->session ?? throw new LogicException(
            'This response has no session. Build the application with sessions enabled.',
        );
    }

    /**
     * Put the actual status and a slice of the body into the failure message,
     * so a red test is readable without a debugger.
     */
    private function explain(string $message): string
    {
        $body = trim($this->response->body());

        if (mb_strlen($body) > 400) {
            $body = mb_substr($body, 0, 400) . '…';
        }

        return sprintf("%s\nGot %d with body:\n%s", $message, $this->response->status(), $body === '' ? '(empty)' : $body);
    }
}
