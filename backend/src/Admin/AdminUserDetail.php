<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use JsonSerializable;

/** Everything the Edit dialog and the account summary show: the list fields and the account's details, side by side. */
final readonly class AdminUserDetail implements JsonSerializable
{
    /**
     * @param array{workspace: int, admin: int} $sessions
     * @param array{active: int, total: int} $repositories
     * @param array{active: int, expired: int, revoked: int, selectedFiles: int, history: int, opens30Days: int} $linkStates
     * @param array{configured: bool, status: string, login: string|null, lastCheckedAt: string|null, errorCode: string|null, credentialVersion: int} $connection
     * @param array{configured: bool, status: string, username: string|null, baseUrl: string|null, tokenExpiresAt: string|null, lastCheckedAt: string|null,
     *   errorCode: string|null, credentialVersion: int} $gitlabConnection
     * @param array{entries: int, bytes: int, upstreamCalls: int} $cache
     */
    public function __construct(
        public AdminUser $account,
        public bool $mustChangePassword,
        public array $sessions,
        public array $repositories,
        public array $linkStates,
        public array $connection,
        public array $gitlabConnection,
        public array $cache,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $details = get_object_vars($this);
        unset($details['account']);
        return get_object_vars($this->account) + $details;
    }
}
