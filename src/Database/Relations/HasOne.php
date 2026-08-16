<?php

declare(strict_types=1);

namespace Phpvin\Database\Relations;

use Phpvin\Database\Model;

/**
 * One parent, at most one child. The foreign key lives on the child table.
 *
 *     public function profile(): HasOne
 *     {
 *         return $this->hasOne(Profile::class);   // profiles.user_id -> users.id
 *     }
 */
final class HasOne extends HasOneOrMany
{
    public function resolve(): ?Model
    {
        if ($this->parentKey() === null) {
            return null;
        }

        /** @var Model|null $model */
        $model = $this->query()->first();

        return $model;
    }

    public function attachTo(array $parents, string $name): void
    {
        $grouped = $this->groupedChildren($parents);

        foreach ($parents as $parent) {
            $children = $grouped[(string) $parent->getAttribute($this->localKey)] ?? [];

            $parent->setRelation($name, $children[0] ?? null);
        }
    }
}
