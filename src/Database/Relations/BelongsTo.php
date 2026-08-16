<?php

declare(strict_types=1);

namespace Phpvin\Database\Relations;

use Phpvin\Database\Model;
use Phpvin\Database\QueryBuilder;

/**
 * The inverse side. The foreign key lives on *this* model's table.
 *
 *     public function author(): BelongsTo
 *     {
 *         return $this->belongsTo(User::class);   // posts.user_id -> users.id
 *     }
 *
 * Here $foreignKey is the column on the parent and $localKey is the column on
 * the related table it points at.
 */
final class BelongsTo extends Relation
{
    public function query(): QueryBuilder
    {
        return $this->related::query()
            ->where($this->localKey, '=', $this->parent->getAttribute($this->foreignKey));
    }

    public function resolve(): ?Model
    {
        if ($this->parent->getAttribute($this->foreignKey) === null) {
            return null;
        }

        /** @var Model|null $model */
        $model = $this->query()->first();

        return $model;
    }

    public function attachTo(array $parents, string $name): void
    {
        $keys = $this->keysOf($parents, $this->foreignKey);

        if ($keys === []) {
            foreach ($parents as $parent) {
                $parent->setRelation($name, null);
            }

            return;
        }

        /** @var list<Model> $owners */
        $owners = $this->related::query()->whereIn($this->localKey, $keys)->get();

        $indexed = [];

        foreach ($owners as $owner) {
            $indexed[(string) $owner->getAttribute($this->localKey)] = $owner;
        }

        foreach ($parents as $parent) {
            $key = $parent->getAttribute($this->foreignKey);
            $parent->setRelation($name, $key === null ? null : ($indexed[(string) $key] ?? null));
        }
    }
}
