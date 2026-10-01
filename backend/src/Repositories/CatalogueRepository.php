<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

/** One repository the owner's token can read, tagged with its platform. */
final readonly class CatalogueRepository
{
    public function __construct(
        public string $provider,
        public int $id,
        public string $fullName,
        public string $owner,
        public string $name,
        public bool $private,
        /** private or public; GitLab also reports internal. */
        public string $visibility,
        public string $defaultBranch,
        public string $description,
    ) {}
}
