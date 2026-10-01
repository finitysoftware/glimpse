<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Sharing\BranchPolicy;
use Glimpse\Sharing\LinkStatus;

/** A link of any owner in the admin panel, with its owner and repository but never its URL. */
final readonly class AdminLink
{
    /**
     * @param array{id: int, username: string, firstName: string|null, lastName: string|null} $owner
     * @param array{provider: string, instance: string, owner: string, name: string, active: bool} $repository
     * @param list<string> $branches
     * @param list<BranchPolicy> $policies
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public array $owner,
        public array $repository,
        public LinkStatus $status,
        public ?string $createdAt,
        public ?string $expiresAt,
        public array $branches,
        public bool $allowHistory,
        public array $policies,
    ) {}
}
