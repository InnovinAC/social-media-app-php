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
        $model = $this->query()->orderBy($this->relatedKey())->first();

        return $model;
    }

    public function attachTo(array $parents, string $name): void
    {
        $grouped = $this->groupedChildren($parents);

        foreach ($parents as $parent) {
            $children = $grouped[(string) $parent->getAttribute($this->localKey)] ?? [];

            $parent->setRelation($name, $this->lowest($children));
        }
    }

    /**
     * Two rows matching a hasOne is a data problem rather than a shape the
     * relation supports, but it happens, and the two loading paths must not
     * then disagree about which row won.
     *
     * They ask different questions: eager takes the first row of a `WHERE key
     * IN (...)` covering every parent, lazy the first of a `WHERE key = ?` for
     * one. Neither is ordered, so nothing obliges an engine to answer them
     * consistently; it happens to today, on this data, until a rebuilt index
     * or a different plan changes its mind. Picking the lowest key on both
     * sides makes "which one" a decision rather than an accident.
     *
     * @param list<Model> $children
     */
    private function lowest(array $children): ?Model
    {
        $lowest = null;

        foreach ($children as $child) {
            if ($lowest === null || $child->key() < $lowest->key()) {
                $lowest = $child;
            }
        }

        return $lowest;
    }
}
