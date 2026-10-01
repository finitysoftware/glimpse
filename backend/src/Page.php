<?php

declare(strict_types=1);

namespace Glimpse;

/**
 * One page of a longer list, with the total count of the whole list.
 *
 * @template T of object
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $page,
        /** The next page, or NULL on the last one. */
        public ?int $nextPage,
        public int $total,
    ) {}
}
