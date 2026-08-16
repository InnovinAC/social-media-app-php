<?php

declare(strict_types=1);

namespace Phpvin\Database\Relations;

use Phpvin\Database\Model;

/**
 * One parent, many children. The foreign key lives on the child table.
 *
 *     public function notes(): HasMany
 *     {
 *         return $this->hasMany(Note::class);   // notes.user_id -> users.id
 *     }
 */
final class HasMany extends HasOneOrMany
{
    /**
     * @return list<Model>
     */
    public function resolve(): array
    {
        if ($this->parentKey() === null) {
            return [];
        }

        /** @var list<Model> $models */
        $models = $this->query()->get();

        return $models;
    }

    public function attachTo(array $parents, string $name): void
    {
        $grouped = $this->groupedChildren($parents);

        foreach ($parents as $parent) {
            $parent->setRelation($name, $grouped[(string) $parent->getAttribute($this->localKey)] ?? []);
        }
    }
}
