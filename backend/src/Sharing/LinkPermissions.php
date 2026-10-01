<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;
use Glimpse\Repositories\BrowseAccess;

/** New branches, History and file visibility of an existing link; its URL and secret stay the same. */
final readonly class LinkPermissions
{
    /**
     * @param list<string> $branches
     * @param list<VisibilityChoice>|null $visibility null when the request sent none; saving then asks for it.
     */
    private function __construct(
        public array $branches,
        public bool $allowHistory,
        /** The link rules version the owner edited; null saves without checking it. */
        public ?int $expectedPolicyVersion,
        public ?array $visibility,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $branches = BrowseAccess::selected($body['branches'] ?? null);
        $history = $body['allowHistory'] ?? null;
        $expected = $body['expectedPolicyVersion'] ?? null;
        if (!is_bool($history) || ($expected !== null && !is_int($expected))) {
            throw Failure::invalid('invalid_permissions', 'Choose whether history is allowed.');
        }
        return new self($branches, $history, $expected, VisibilityChoice::listFrom($body['visibility'] ?? null));
    }
}
