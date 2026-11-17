<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Domain;

/**
 * Immutable collection of License entities.
 */
final readonly class LicenseCollection
{
    /**
     * @param list<License> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $pageSize,
        public int $total,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function totalPages(): int
    {
        return $this->pageSize > 0 ? (int)ceil($this->total / $this->pageSize) : 0;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,page:int,page_size:int,total:int,total_pages:int}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (License $l) => $l->toArray(), $this->items),
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'total' => $this->total,
            'total_pages' => $this->totalPages(),
        ];
    }
}
