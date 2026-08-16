<?php

declare(strict_types=1);

namespace Phpvin\Database\Relations;

use Phpvin\Database\Model;
use Phpvin\Database\QueryBuilder;

/**
 * A link between two models.
 *
 * Each relation can resolve itself two ways: for one parent (lazy, one query)
 * or for a whole set of parents at once (eager, still one query). The eager
 * path is what `Model::query()->with('comments')` uses, and it is the
 * difference between one extra query and one per row.
 */
abstract class Relation
{
    /**
     * @param class-string<Model> $related
     */
    public function __construct(
        protected readonly string $related,
        protected readonly string $foreignKey,
        protected readonly string $localKey,
        protected readonly Model $parent,
    ) {}

    /**
     * A query for the related records, scoped to this relation's parent.
     */
    abstract public function query(): QueryBuilder;

    /**
     * Resolve for the single parent this relation was built from.
     */
    abstract public function resolve(): mixed;

    /**
     * Load for many parents at once and attach the results to each.
     *
     * @param list<Model> $parents
     */
    abstract public function attachTo(array $parents, string $name): void;

    /** @return class-string<Model> */
    public function relatedClass(): string
    {
        return $this->related;
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    /**
     * The distinct, non-null values of $key across $parents.
     *
     * @param  list<Model> $parents
     * @return list<mixed>
     */
    protected function keysOf(array $parents, string $key): array
    {
        $keys = [];

        foreach ($parents as $parent) {
            $value = $parent->getAttribute($key);

            if ($value !== null) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }
}
