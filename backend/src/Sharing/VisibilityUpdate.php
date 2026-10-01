<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;

/** The saved file visibility of some branches, applied to a link; its other branches keep their rules. */
final readonly class VisibilityUpdate
{
    /** @param list<VisibilityChoice> $visibility At least one branch. */
    private function __construct(
        /** The link rules version the owner saw. */
        public int $expectedPolicyVersion,
        public array $visibility,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $expected = $body['expectedPolicyVersion'] ?? null;
        $requested = $body['visibility'] ?? null;
        if (!is_int($expected) || !is_array($requested) || !array_is_list($requested) || $requested === []) {
            throw Failure::invalid('invalid_visibility', 'Choose the branches whose current file visibility this link should use.');
        }
        $visibility = VisibilityChoice::fromList($requested);
        return new self($expected, $visibility);
    }
}
