<?php

declare(strict_types=1);

namespace App\Models;

use Phpvin\Database\Model;
use Phpvin\Database\Relations\BelongsTo;

final class Note extends Model
{
    /** @var list<string> */
    protected static array $fillable = ['body'];

    /**
     * The relation. Note the name: `belongsTo` itself is the framework's
     * builder method, so a model's own methods must not shadow it.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<self>
     */
    public static function forUser(int $userId): array
    {
        /** @var list<self> $notes */
        $notes = self::query()
            ->where('user_id', '=', $userId)
            ->orderBy('id', 'desc')
            ->get();

        return $notes;
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->user_id === $userId;
    }
}
