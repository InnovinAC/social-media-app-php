<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\JsonResponse;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Http\Session;

final class HttpTest extends TestCase
{
    #[Test]
    public function it_normalises_the_path(): void
    {
        $this->assertSame('/posts', Request::create('GET', '/posts/')->path);
        $this->assertSame('/posts', Request::create('GET', '/posts?page=2')->path);
        $this->assertSame('/', Request::create('GET', '/')->path);
    }

    #[Test]
    public function input_prefers_the_body_over_the_query_string(): void
    {
        $request = Request::create('POST', '/', body: ['name' => 'body'], query: ['name' => 'query']);

        $this->assertSame('body', $request->input('name'));
        $this->assertSame('query', $request->query('name'));
        $this->assertSame('body', $request->post('name'));
    }

    #[Test]
    public function only_returns_a_whitelisted_subset_and_omits_missing_keys(): void
    {
        $request = Request::create('POST', '/', body: ['email' => 'a@b.c', 'is_admin' => '1']);

        $this->assertSame(['email' => 'a@b.c'], $request->only(['email', 'nickname']));
    }

    #[Test]
    public function headers_are_matched_case_insensitively(): void
    {
        $request = Request::create('GET', '/', headers: ['x-csrf-token' => 'abc']);

        $this->assertSame('abc', $request->header('X-CSRF-Token'));
        $this->assertNull($request->header('x-missing'));
    }

    #[Test]
    public function it_treats_get_head_and_options_as_reads(): void
    {
        $this->assertTrue(Request::create('GET', '/')->isReading());
        $this->assertTrue(Request::create('HEAD', '/')->isReading());
        $this->assertFalse(Request::create('POST', '/')->isReading());
    }

    #[Test]
    public function it_detects_clients_that_want_json(): void
    {
        $this->assertTrue(Request::create('GET', '/', headers: ['accept' => 'application/json'])->wantsJson());
        $this->assertTrue(Request::create('GET', '/', headers: ['x-requested-with' => 'XMLHttpRequest'])->wantsJson());
        $this->assertFalse(Request::create('GET', '/', headers: ['accept' => 'text/html'])->wantsJson());
    }

    #[Test]
    public function route_parameters_are_attached_without_mutating_the_original(): void
    {
        $request = Request::create('GET', '/posts/1');
        $withParams = $request->withRouteParameters(['id' => '1']);

        $this->assertSame([], $request->routeParameters);
        $this->assertSame('1', $withParams->routeParameter('id'));
    }

    #[Test]
    public function asking_for_an_absent_session_fails_loudly(): void
    {
        $this->expectException(LogicException::class);

        Request::create('GET', '/')->session();
    }

    #[Test]
    public function a_response_carries_status_body_and_headers(): void
    {
        $response = (new Response('hi', 201))->header('X-Test', 'yes');

        $this->assertSame(201, $response->status());
        $this->assertSame('hi', $response->body());
        $this->assertSame('yes', $response->getHeader('x-test'));
        $this->assertTrue($response->isSuccessful());
    }

    #[Test]
    public function a_json_response_encodes_its_payload_and_sets_the_content_type(): void
    {
        $response = new JsonResponse(['ok' => true, 'items' => [1, 2]]);

        $this->assertSame('{"ok":true,"items":[1,2]}', $response->body());
        $this->assertStringContainsString('application/json', (string) $response->getHeader('content-type'));
    }

    #[Test]
    public function a_redirect_sets_the_location_header(): void
    {
        $response = new RedirectResponse('/login');

        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->getHeader('location'));
        $this->assertTrue($response->isRedirect());
    }

    #[Test]
    public function a_redirect_can_flash_errors_and_old_input(): void
    {
        $session = new Session([]);

        (new RedirectResponse('/register'))->withErrors($session, ['email' => ['Taken.']], ['email' => 'a@b.c']);

        $next = new Session($session->all());

        $this->assertSame(['email' => ['Taken.']], $next->flashed('errors'));
        $this->assertSame(['email' => 'a@b.c'], $next->flashed('old'));
    }

    #[Test]
    public function flashed_data_survives_exactly_one_request(): void
    {
        $first = new Session([]);
        $first->flash('status', 'Saved.');

        $second = new Session($first->all());
        $this->assertSame('Saved.', $second->flashed('status'));

        $third = new Session($second->all());
        $this->assertNull($third->flashed('status'));
    }

    #[Test]
    public function ordinary_session_values_are_not_aged_out(): void
    {
        $first = new Session([]);
        $first->put('user_id', 7);

        $second = new Session($first->all());
        $third = new Session($second->all());

        $this->assertSame(7, $third->get('user_id'));
    }

    #[Test]
    public function the_csrf_token_is_stable_within_a_session(): void
    {
        $session = new Session([]);

        $this->assertSame($session->csrfToken(), $session->csrfToken());
        $this->assertSame(64, strlen($session->csrfToken()));
    }

    #[Test]
    public function csrf_verification_rejects_wrong_empty_and_null_tokens(): void
    {
        $session = new Session([]);
        $token = $session->csrfToken();

        $this->assertTrue($session->verifyCsrf($token));
        $this->assertFalse($session->verifyCsrf('wrong'));
        $this->assertFalse($session->verifyCsrf(''));
        $this->assertFalse($session->verifyCsrf(null));
    }

    #[Test]
    public function forget_removes_a_value(): void
    {
        $session = new Session([]);
        $session->put('user_id', 7);
        $session->forget('user_id');

        $this->assertFalse($session->has('user_id'));
    }
}
