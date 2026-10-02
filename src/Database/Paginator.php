<?php

declare(strict_types=1);

namespace Phpvin\Database;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of results and the numbers needed to render page links.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 */
final class Paginator implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function nextPage(): ?int
    {
        return $this->hasMorePages() ? $this->currentPage + 1 : null;
    }

    public function previousPage(): ?int
    {
        return $this->onFirstPage() ? null : $this->currentPage - 1;
    }

    /** Index of the first item on this page, 1-based. Null when empty. */
    public function from(): ?int
    {
        return $this->items === [] ? null : ($this->currentPage - 1) * $this->perPage + 1;
    }

    /** Index of the last item on this page, 1-based. Null when empty. */
    public function to(): ?int
    {
        return $this->items === [] ? null : ($this->currentPage - 1) * $this->perPage + count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage(),
            'from' => $this->from(),
            'to' => $this->to(),
        ];
    }
}
