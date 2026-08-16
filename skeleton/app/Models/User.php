<?php

declare(strict_types=1);

namespace App\Models;

use Phpvin\Database\Model;

final class User extends Model
{
    /**
     * Note what is *not* here: `password`. A column left out of $fillable
     * cannot be written by mass assignment, so no amount of extra form fields
     * can set it. Use setPassword() instead.
     *
     * @var list<string>
     */
    protected static array $fillable = ['name', 'email'];

    /** @var list<string> */
    protected static array $hidden = ['password'];

    public static function findByEmail(string $email): ?self
    {
        /** @var self|null $user */
        $user = self::query()->where('email', '=', $email)->first();

        return $user;
    }

    public static function emailIsTaken(string $email): bool
    {
        return self::query()->where('email', '=', $email)->exists();
    }

    public function setPassword(string $plain): void
    {
        $this->password = password_hash($plain, PASSWORD_DEFAULT);
    }

    public function verifyPassword(string $plain): bool
    {
        return is_string($this->password) && password_verify($plain, $this->password);
    }

    /**
     * True when the stored hash was made with outdated parameters and should
     * be replaced next time we have the plaintext.
     */
    public function passwordNeedsRehash(): bool
    {
        return is_string($this->password) && password_needs_rehash($this->password, PASSWORD_DEFAULT);
    }
}
