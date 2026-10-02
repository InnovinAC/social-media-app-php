<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Phpvin\Http\Session;

/**
 * Who is signed in.
 *
 * Inject it. There is no `auth()` helper and no static accessor, so a test can
 * hand it a detached Session and get a fully working instance.
 */
final class Auth
{
    private ?User $cached = null;

    private bool $looked = false;

    public function __construct(private readonly Session $session) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $id = $this->session->get('user_id');

        return is_int($id) ? $id : null;
    }

    public function user(): ?User
    {
        if ($this->looked) {
            return $this->cached;
        }

        $this->looked = true;
        $id = $this->id();

        return $this->cached = $id === null ? null : User::find($id);
    }

    /**
     * Verify a password and sign the user in.
     *
     * @return User|null The signed-in user, or null when the credentials do
     *                   not match.
     */
    public function attempt(string $email, string $password): ?User
    {
        $user = User::findByEmail($email);

        if ($user === null) {
            // Hash anyway so a missing account and a wrong password take about
            // the same time, and the response cannot be used to enumerate
            // which addresses are registered.
            password_verify($password, '$2y$12$usesomesillystringfor.eSpeedUpBcryptHashingXXXXXXXXXXXXX');

            return null;
        }

        if (! $user->verifyPassword($password)) {
            return null;
        }

        if ($user->passwordNeedsRehash()) {
            $user->setPassword($password);
            $user->save();
        }

        $this->login($user);

        return $user;
    }

    public function login(User $user): void
    {
        // New session id on privilege change, so a token captured before login
        // is worthless afterwards.
        $this->session->regenerate();
        $this->session->put('user_id', (int) $user->key());

        $this->cached = $user;
        $this->looked = true;
    }

    public function logout(): void
    {
        $this->session->forget('user_id');
        $this->session->regenerate();

        $this->cached = null;
        $this->looked = true;
    }
}
