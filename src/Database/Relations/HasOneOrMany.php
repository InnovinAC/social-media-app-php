<?php

declare(strict_types=1);

namespace Phpvin\Database\Relations;

use Phpvin\Database\Model;
use Phpvin\Database\QueryBuilder;

/**
 * Shared machinery for the two relations whose foreign key lives on the child
 * table. They differ only in whether the result is a list or a single model,
 * which is too small a difference to justify one inheriting from the other:
 * doing that would force incompatible return types.
 */
abstract class HasOneOrMany extends Relation
{
    public function query(): QueryBuilder
    {
        return $this->related::query()
            ->where($this->foreignKey, '=', $this->parent->getAttribute($this->localKey));
    }

    /**
     * Fetch every child of every parent in one query, grouped by foreign key.
     *
     * @param  list<Model> $parents
     * @return array<string, list<Model>>
     */
    protected function groupedChildren(array $parents): array
    {
        $keys = $this->keysOf($parents, $this->localKey);

        if ($keys === []) {
            return [];
        }

        /** @var list<Model> $children */
        $children = $this->related::query()->whereIn($this->foreignKey, $keys)->get();

        $grouped = [];

        foreach ($children as $child) {
            $grouped[(string) $child->getAttribute($this->foreignKey)][] = $child;
        }

        return $grouped;
    }

    protected function parentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }
}
