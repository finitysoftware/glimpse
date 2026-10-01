<?php

declare(strict_types=1);

namespace Glimpse\Auth;

/** The verified owner of a workspace request, derived only from its SQL session; never from request data. */
final readonly class UserContext
{
    public const WORKSPACE = 'workspace';

    /** @param list<string> $permissions */
    public function __construct(
        public int $userId,
        public string $username,
        public int $sessionId,
        public int $sessionVersion,
        public array $permissions,
        public string $csrfToken,
        /** Set by an administrator: until the password changes only session, password and logout are allowed. */
        public bool $mustChangePassword = false,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
