<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Failure;

/** The search, filters, sort order and page of the admin users table; '' means no filter. */
final readonly class UserFilter
{
    /** Connection states an account can be filtered by, for GitHub and GitLab alike. */
    public const CONNECTION_STATES = [
        'connected',
        'not_verified',
        'credentials_rejected',
        'access_restricted',
        'temporarily_unavailable',
        'not_configured',
    ];

    private function __construct(
        public string $search,
        /** active or disabled */
        public string $status,
        /** admin or user */
        public string $role,
        public string $github,
        public string $gitlab,
        /** name (the default), username, created or last_sign_in */
        public string $sort,
        public int $page,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $text = static function (string $key) use ($query): string {
            $value = $query[$key] ?? '';
            if (!is_string($value) || strlen($value) > 100) {
                throw Failure::invalid('invalid_input', 'Invalid filter.');
            }
            return trim($value);
        };
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 100000) {
            throw Failure::invalid('invalid_input', 'Invalid page.');
        }
        $filter = new self($text('q'), $text('status'), $text('role'), $text('github'), $text('gitlab'), $text('sort'), $page);
        if (!in_array($filter->status, ['', 'active', 'disabled'], true)
            || !in_array($filter->role, ['', 'admin', 'user'], true)
            || !in_array($filter->github, ['', ...self::CONNECTION_STATES], true)
            || !in_array($filter->gitlab, ['', ...self::CONNECTION_STATES], true)
            || !in_array($filter->sort, ['', 'name', 'username', 'created', 'last_sign_in'], true)) {
            throw Failure::invalid('invalid_input', 'Invalid filter.');
        }
        return $filter;
    }
}
