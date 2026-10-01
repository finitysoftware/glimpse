<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Failure;

/** The search, owner, status and page of the admin access links table; '' and null mean no filter. */
final readonly class LinkFilter
{
    private function __construct(
        public string $search,
        /** The owner's account ID. */
        public ?int $user,
        /** active, expired or revoked */
        public string $status,
        public int $page,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $text = static function (string $key) use ($query): string {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 200) {
                throw Failure::invalid('invalid_input', 'Invalid filter.');
            }
            return trim($value);
        };
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT);
        $user = $text('user');
        $status = $text('status');
        $search = $text('q');
        if ($page === false || $page < 1 || $page > 100000 || !in_array($status, ['', 'active', 'expired', 'revoked'], true)
            || ($user !== '' && preg_match('/^[1-9][0-9]{0,17}$/D', $user) !== 1)) {
            throw Failure::invalid('invalid_input', 'Invalid filter.');
        }
        return new self($search, $user === '' ? null : (int) $user, $status, $page);
    }
}
