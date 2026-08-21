<?php

declare(strict_types=1);

namespace App\Tests;

use App\Models\User;

final class AuthTest extends AppTestCase
{
    public function test_a_guest_is_redirected_away_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_registration_creates_an_account_and_signs_in(): void
    {
        $this->registerAndSignIn();

        $this->assertSame(1, User::query()->count());
        $this->get('/dashboard')->assertOk()->assertSee('ada@example.com');
    }

    public function test_the_password_is_hashed_not_stored(): void
    {
        $this->registerAndSignIn();

        $stored = User::query()->where('email', '=', 'ada@example.com')->first()->password;

        $this->assertNotSame('correcthorsebattery', $stored);
        $this->assertTrue(password_verify('correcthorsebattery', $stored));
    }

    public function test_registration_rejects_a_short_password(): void
    {
        $this->post('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertValidationErrors(['password']);

        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_rejects_a_mismatched_confirmation(): void
    {
        $this->post('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'correcthorsebattery',
            'password_confirmation' => 'somethingelse',
        ])->assertValidationErrors(['password']);
    }

    public function test_an_email_cannot_be_registered_twice(): void
    {
        $this->registerAndSignIn();
        $this->post('/logout')->assertRedirect('/');

        $this->post('/register', [
            'name' => 'Impostor',
            'email' => 'ada@example.com',
            'password' => 'correcthorsebattery',
            'password_confirmation' => 'correcthorsebattery',
        ])->assertValidationErrors(['email']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_signing_in_with_the_wrong_password_fails(): void
    {
        $this->registerAndSignIn();
        $this->post('/logout');

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertValidationErrors(['email']);
    }

    public function test_signing_in_with_the_right_password_works(): void
    {
        $this->registerAndSignIn();
        $this->post('/logout');

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'correcthorsebattery'])
            ->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk();
    }

    public function test_a_signed_in_user_is_kept_away_from_the_login_page(): void
    {
        $this->registerAndSignIn();

        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_a_post_without_a_csrf_token_is_rejected(): void
    {
        $this->withoutCsrfToken()
            ->post('/login', ['email' => 'ada@example.com', 'password' => 'x'])
            ->assertStatus(419);
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->registerAndSignIn();
        $this->post('/logout');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => 'ada@example.com', 'password' => "wrong$attempt"])
                ->assertRedirect('/login');
        }

        $this->post('/login', ['email' => 'ada@example.com', 'password' => 'wrong6'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
