<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

/** One page of the repositories of one platform. */
final readonly class Catalogue
{
    /** @param list<CatalogueRepository> $items */
    public function __construct(public array $items, public ?int $nextPage) {}
}
