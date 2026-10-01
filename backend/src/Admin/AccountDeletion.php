<?php

declare(strict_types=1);

namespace Glimpse\Admin;

/** Deleting an account: only the version the administrator saw, so a changed account is never deleted unseen. */
final readonly class AccountDeletion
{
    private function __construct(public int $expectedVersion) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        return new self(AdminUserInput::version($body['expectedVersion'] ?? null));
    }
}
