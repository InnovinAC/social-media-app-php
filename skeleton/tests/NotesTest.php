<?php

declare(strict_types=1);

namespace App\Tests;

use App\Models\Note;

/**
 * The AJAX path, exercised server-side.
 *
 * The same controller answers a normal form post with a redirect and an AJAX
 * post with a command list. Both are tested here, because "works with
 * JavaScript" and "works without it" are two different promises.
 */
final class NotesTest extends AppTestCase
{
    public function test_a_note_can_be_added_with_a_normal_form_post(): void
    {
        $this->registerAndSignIn();

        $this->post('/notes', ['body' => 'written without javascript'])
            ->assertRedirect('/dashboard');

        $this->assertSame(1, Note::query()->count());
        $this->get('/dashboard')->assertSee('written without javascript');
    }

    public function test_an_ajax_post_returns_a_command_list(): void
    {
        $this->registerAndSignIn();

        $response = $this->json('POST', '/notes', ['body' => 'added over ajax'])
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/vnd.phpvin.commands+json');

        $commands = array_column($response->json(), 'command');

        // One round trip updates the list, the empty state, the counter and
        // the focus.
        $this->assertSame(['prepend', 'remove', 'text', 'focus', 'trigger'], $commands);
    }

    public function test_the_prepended_fragment_carries_its_own_delete_link(): void
    {
        $this->registerAndSignIn();

        $html = $this->json('POST', '/notes', ['body' => 'fresh'])->json()[0]['html'];

        $this->assertStringContainsString('data-method="delete"', $html);
        $this->assertStringContainsString('fresh', $html);
    }

    public function test_an_empty_note_is_rejected(): void
    {
        $this->registerAndSignIn();

        $this->json('POST', '/notes', ['body' => ''])
            ->assertStatus(422)
            ->assertValidationErrors(['body']);

        $this->assertSame(0, Note::query()->count());
    }

    public function test_a_note_can_be_deleted_over_ajax(): void
    {
        $this->registerAndSignIn();
        $this->post('/notes', ['body' => 'temporary']);

        $id = Note::query()->first()->key();

        $this->json('DELETE', "/notes/$id")->assertOk();

        $this->assertSame(0, Note::query()->count());
    }

    public function test_one_user_cannot_delete_another_users_note(): void
    {
        $this->registerAndSignIn('ada@example.com');
        $this->post('/notes', ['body' => "ada's note"]);
        $id = Note::query()->first()->key();
        $this->post('/logout');

        $this->registerAndSignIn('mallory@example.com');

        // Answered as "not found" rather than "forbidden", so probing for
        // other people's ids tells an attacker nothing.
        $this->json('DELETE', "/notes/$id")->assertNotFound();

        $this->assertSame(1, Note::query()->count(), "ada's note is untouched");
    }

    public function test_deleting_a_note_that_does_not_exist_is_a_404(): void
    {
        $this->registerAndSignIn();

        $this->json('DELETE', '/notes/9999')->assertNotFound();
    }

    public function test_a_guest_cannot_add_a_note(): void
    {
        $this->post('/notes', ['body' => 'sneaky'])->assertRedirect('/login');

        $this->assertSame(0, Note::query()->count());
    }

    public function test_a_guest_gets_401_rather_than_a_redirect_over_ajax(): void
    {
        $this->json('POST', '/notes', ['body' => 'sneaky'])->assertUnauthorised();
    }
}
