<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;
use Glimpse\Fields;
use Glimpse\Repositories\BrowseAccess;

/** An access link the owner creates: its name, expiry, branches, History and file visibility per branch. */
final readonly class NewShare
{
    /**
     * @param list<string>|null $branches null shares the repository's default branch.
     * @param list<VisibilityChoice>|null $visibility null when the request sent none; saving then asks for it.
     */
    private function __construct(
        public string $name,
        /** null: the link never expires. */
        public ?int $expiresInDays,
        public ?array $branches,
        public bool $allowHistory,
        public ?array $visibility,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $name = trim(Fields::text($body, 'name'));
        $days = array_key_exists('expiresInDays', $body) ? $body['expiresInDays'] : 7;
        if ($name === '' || mb_strlen($name) > 160 || ($days !== null && (!is_int($days) || $days < 1 || $days > 365))) {
            throw Failure::invalid('invalid_share', 'Enter a link name and an expiry between 1 and 365 days, or no expiry.');
        }
        $branches = array_key_exists('branches', $body) ? BrowseAccess::selected($body['branches']) : null;
        $history = array_key_exists('allowHistory', $body) ? $body['allowHistory'] : false;
        if (!is_bool($history)) {
            throw Failure::invalid('invalid_permissions', 'Choose whether history is allowed.');
        }
        return new self($name, $days, $branches, $history, VisibilityChoice::listFrom($body['visibility'] ?? null));
    }
}
