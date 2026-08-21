<?php

declare(strict_types=1);

namespace Phpvin\Testing;

use PHPUnit\Framework\TestCase;
use Phpvin\Application;
use Phpvin\Database\Connection;
use Phpvin\Database\Model;
use Phpvin\Http\Request;
use Phpvin\Http\Session;

/**
 * A base test case for applications built on phpvin.
 *
 *     final class NotesTest extends ApplicationTestCase
 *     {
 *         protected function createApplication(): Application
 *         {
 *             return require __DIR__ . '/../bootstrap.php';
 *         }
 *
 *         public function test_a_note_can_be_added(): void
 *         {
 *             $this->withSession(['user_id' => 1])
 *                 ->post('/notes', ['body' => 'hello'])
 *                 ->assertRedirect('/dashboard')
 *                 ->assertSessionHas('success');
 *         }
 *     }
 *
 * Requests go straight through `Application::handle()` (no HTTP server, no
 * superglobals), so a test is as fast as a function call and as honest as a
 * real request. The session persists between calls the way a browser's cookie
 * jar would, and the CSRF token is attached automatically, because typing it
 * out in every test would only ever be noise.
 */
abstract class ApplicationTestCase extends TestCase
{
    protected Application $app;

    protected Session $session;

    private bool $sendCsrfToken = true;

    private bool $followRedirects = false;

    /**
     * Build the application under test. Usually the same bootstrap the front
     * controller uses, so the test exercises the real wiring.
     */
    abstract protected function createApplication(): Application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new Session([]);
        $this->app = $this->createApplication();
    }

    // --- state before the request -------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    protected function withSession(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->session->put($key, $value);
        }

        return $this;
    }

    /**
     * Send the next request without a CSRF token, to prove the guard works.
     */
    protected function withoutCsrfToken(): static
    {
        $this->sendCsrfToken = false;

        return $this;
    }

    /**
     * Follow redirects the way a browser would, up to a sane limit.
     */
    protected function followingRedirects(): static
    {
        $this->followRedirects = true;

        return $this;
    }

    /**
     * Point the application's models at a throwaway database.
     *
     * Returns the connection so migrations and fixtures can be applied.
     */
    protected function useInMemoryDatabase(): Connection
    {
        $connection = Connection::sqliteInMemory();
        Model::useConnection($connection);

        return $connection;
    }

    // --- making requests -----------------------------------------------------

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, headers: $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function post(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function put(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('PUT', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function patch(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('PATCH', $uri, $body, $headers);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function delete(string $uri, array $body = [], array $headers = []): TestResponse
    {
        return $this->call('DELETE', $uri, $body, $headers);
    }

    /**
     * Make a request as an API client: JSON in, JSON expected back.
     *
     * @param array<string, mixed> $body
     */
    protected function json(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->call($method, $uri, $body, [
            'accept' => 'application/json',
            'x-requested-with' => 'XMLHttpRequest',
        ]);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $uri, array $body = [], array $headers = []): TestResponse
    {
        $method = strtoupper($method);

        // A browser gets the token from the rendered form; a test should not
        // have to fetch a page first just to post to one.
        if ($this->sendCsrfToken && ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
            $body['_token'] ??= $this->session->csrfToken();
            $headers['x-csrf-token'] ??= $this->session->csrfToken();
        }

        $response = $this->app->handle(Request::create($method, $uri, $body, headers: $headers, session: $this->session));

        // Flash written during this request becomes readable on the next one,
        // which is the same ageing a second HTTP request would cause.
        $this->session = new Session($this->session->all());

        $this->sendCsrfToken = true;

        if ($this->followRedirects) {
            $response = $this->follow($response);
        }

        return new TestResponse($response, $this->session);
    }

    /**
     * Follow up to $limit redirects, then give up.
     *
     * A bounded loop rather than a countdown inside the condition: a redirect
     * loop in the application under test should fail the assertion, not hang
     * the test run.
     */
    private function follow(\Phpvin\Http\Response $response, int $limit = 5): \Phpvin\Http\Response
    {
        for ($hop = 0; $hop < $limit; $hop++) {
            if (! $response->isRedirect()) {
                return $response;
            }

            $location = $response->getHeader('location');

            if ($location === null) {
                return $response;
            }

            $response = $this->app->handle(Request::create('GET', $location, session: $this->session));
            $this->session = new Session($this->session->all());
        }

        return $response;
    }
}
