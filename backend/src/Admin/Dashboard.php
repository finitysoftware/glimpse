<?php

declare(strict_types=1);

namespace Glimpse\Admin;

/** Platform-wide numbers for the admin dashboard: aggregates only, without names, paths or secrets. */
final readonly class Dashboard
{
    /**
     * @param array{total: int, active: int, disabled: int, admins: int, signedInLast7Days: int, withoutGitHub: int, withoutGitLab: int} $users
     * @param array{connected: int, notVerified: int, credentialsRejected: int, accessRestricted: int, temporarilyUnavailable: int, notConfigured: int} $github
     * @param array{connected: int, notVerified: int, credentialsRejected: int, accessRestricted: int, temporarilyUnavailable: int, notConfigured: int,
     *   expiringSoon: int} $gitlab
     * @param array{active: int, expired: int, revoked: int, selectedFiles: int, history: int} $links
     * @param array{activeSessions: int, opensLast7Days: int, opensLast30Days: int} $visitors
     * @param array{bytes: int, maxBytes: int, entries: int, upstreamCalls: int, revalidations: int, evictions: int, usersInCooldown: int} $cache
     * @param array{phpVersion: string, databaseVersion: string|null, migrations: array{applied: int, total: int, latest: string|null, pending: list<string>},
     *   maintenance: bool, environment: string, workspaceOrigins: list<string>, sharePublicOrigin: string, shareKeyConfigured: bool,
     *   githubTokenKeyId: string|null} $system
     * @param list<array{day: string, workspaceLogins: int, linkOpens: int, linksCreated: int}> $series Oldest day first.
     */
    public function __construct(
        public string $generatedAt,
        public array $users,
        public array $github,
        public array $gitlab,
        public array $links,
        public array $visitors,
        public array $cache,
        public array $system,
        public array $series,
    ) {}
}
