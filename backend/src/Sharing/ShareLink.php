<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

/** One access link in its owner's list; the URL itself is shown only on request. */
final readonly class ShareLink
{
    /**
     * @param list<string> $branches
     * @param list<BranchPolicy> $policies
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public string $createdAt,
        public ?string $expiresAt,
        public LinkStatus $status,
        public array $branches,
        public bool $allowHistory,
        public int $policyVersion,
        public array $policies,
    ) {}
}
