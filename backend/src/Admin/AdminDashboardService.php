<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Database\Connection;
use Glimpse\Database\Migrator;
use Glimpse\Settings;
use Glimpse\Sharing\LinkStatus;
use Glimpse\Sharing\ShareUrls;
use PDO;

/** Platform-wide numbers for the admin dashboard: aggregates only, without names, paths or secrets. */
final readonly class AdminDashboardService
{
    public const SERIES_DAYS = 14;

    public function __construct(private Connection $connection, private Settings $settings, private Migrator $migrator, private ShareUrls $urls) {}

    public function overview(): Dashboard
    {
        $pdo = $this->connection->get();
        $one = static function (string $sql) use ($pdo): array {
            $read = $pdo->query($sql);
            $row = $read === false ? false : $read->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? array_map(static fn(mixed $value): int => (int) $value, $row) : [];
        };
        $users = $one("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'active'), 0) AS active, COALESCE(SUM(status = 'disabled'), 0) AS disabled,
            COALESCE(SUM(last_login_at > DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 7 DAY)), 0) AS signed_in,
            COALESCE(SUM(NOT EXISTS (SELECT 1 FROM github_connections c WHERE c.user_id = users.id)), 0) AS without_github,
            COALESCE(SUM(NOT EXISTS (SELECT 1 FROM gitlab_connections g WHERE g.user_id = users.id)), 0) AS without_gitlab,
            (SELECT COUNT(*) FROM user_roles r JOIN users a ON a.id = r.user_id AND a.status = 'active' WHERE r.role = 'platform_admin') AS admins FROM users");
        $github = $one("SELECT COALESCE(SUM(last_check_status = 'connected'), 0) AS connected, COALESCE(SUM(last_check_status = 'not_verified'), 0) AS not_verified,
            COALESCE(SUM(last_check_status = 'credentials_rejected'), 0) AS rejected, COALESCE(SUM(last_check_status = 'access_restricted'), 0) AS restricted,
            COALESCE(SUM(last_check_status = 'temporarily_unavailable'), 0) AS unavailable FROM github_connections");
        $gitlab = $one("SELECT COALESCE(SUM(last_check_status = 'connected'), 0) AS connected, COALESCE(SUM(last_check_status = 'not_verified'), 0) AS not_verified,
            COALESCE(SUM(last_check_status = 'credentials_rejected'), 0) AS rejected, COALESCE(SUM(last_check_status = 'access_restricted'), 0) AS restricted,
            COALESCE(SUM(last_check_status = 'temporarily_unavailable'), 0) AS unavailable,
            COALESCE(SUM(token_expires_at IS NOT NULL AND token_expires_at <= DATE_ADD(UTC_DATE(), INTERVAL 14 DAY)), 0) AS expiring FROM gitlab_connections");
        [$active, $expired] = [LinkStatus::ACTIVE_SQL, LinkStatus::EXPIRED_SQL];
        $links = $one("SELECT COALESCE(SUM($active), 0) AS active, COALESCE(SUM($expired), 0) AS expired,
            COALESCE(SUM(l.revoked_at IS NOT NULL), 0) AS revoked, COALESCE(SUM($active AND l.allow_history = 1), 0) AS history,
            COALESCE(SUM($active AND EXISTS (SELECT 1 FROM share_branch_policies b WHERE b.share_link_id = l.id AND b.mode = 'selected')), 0) AS selected
            FROM share_links l");
        $visitors = $one("SELECT (SELECT COUNT(*) FROM sessions WHERE audience = 'visitor' AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)) AS sessions,
            (SELECT COALESCE(SUM(count), 0) FROM daily_metrics WHERE metric = 'link_opened' AND day > DATE_SUB(UTC_DATE(), INTERVAL 7 DAY)) AS week,
            (SELECT COALESCE(SUM(count), 0) FROM daily_metrics WHERE metric = 'link_opened' AND day > DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)) AS month");
        $cache = $one('SELECT c.reserved_bytes AS bytes, c.reserved_entries AS entries,
            (SELECT COALESCE(SUM(upstream_calls), 0) FROM api_cache_state) AS upstream, (SELECT COALESCE(SUM(revalidations), 0) FROM api_cache_state) AS revalidations,
            (SELECT COALESCE(SUM(evictions), 0) FROM api_cache_state) AS evictions,
            (SELECT COUNT(DISTINCT user_id) FROM api_cache_state WHERE blocked_until > UTC_TIMESTAMP(6)) AS cooldown FROM cache_capacity_state c WHERE c.id = 1');
        return new Dashboard(
            gmdate('Y-m-d\TH:i:s\Z'),
            ['total' => $users['total'] ?? 0, 'active' => $users['active'] ?? 0, 'disabled' => $users['disabled'] ?? 0, 'admins' => $users['admins'] ?? 0,
                'signedInLast7Days' => $users['signed_in'] ?? 0, 'withoutGitHub' => $users['without_github'] ?? 0, 'withoutGitLab' => $users['without_gitlab'] ?? 0],
            ['connected' => $github['connected'] ?? 0, 'notVerified' => $github['not_verified'] ?? 0, 'credentialsRejected' => $github['rejected'] ?? 0,
                'accessRestricted' => $github['restricted'] ?? 0, 'temporarilyUnavailable' => $github['unavailable'] ?? 0, 'notConfigured' => $users['without_github'] ?? 0],
            ['connected' => $gitlab['connected'] ?? 0, 'notVerified' => $gitlab['not_verified'] ?? 0, 'credentialsRejected' => $gitlab['rejected'] ?? 0,
                'accessRestricted' => $gitlab['restricted'] ?? 0, 'temporarilyUnavailable' => $gitlab['unavailable'] ?? 0, 'notConfigured' => $users['without_gitlab'] ?? 0,
                'expiringSoon' => $gitlab['expiring'] ?? 0],
            ['active' => $links['active'] ?? 0, 'expired' => $links['expired'] ?? 0, 'revoked' => $links['revoked'] ?? 0,
                'selectedFiles' => $links['selected'] ?? 0, 'history' => $links['history'] ?? 0],
            ['activeSessions' => $visitors['sessions'] ?? 0, 'opensLast7Days' => $visitors['week'] ?? 0, 'opensLast30Days' => $visitors['month'] ?? 0],
            ['bytes' => $cache['bytes'] ?? 0, 'maxBytes' => $this->settings->cacheBudgetBytes, 'entries' => $cache['entries'] ?? 0,
                'upstreamCalls' => $cache['upstream'] ?? 0, 'revalidations' => $cache['revalidations'] ?? 0, 'evictions' => $cache['evictions'] ?? 0,
                'usersInCooldown' => $cache['cooldown'] ?? 0],
            $this->system($pdo),
            $this->series($pdo),
        );
    }

    /** @return array{phpVersion: string, databaseVersion: string|null, migrations: array{applied: int, total: int, latest: string|null, pending: list<string>},
     *   maintenance: bool, environment: string, workspaceOrigins: list<string>, sharePublicOrigin: string, shareKeyConfigured: bool, githubTokenKeyId: string|null}
     */
    private function system(PDO $pdo): array
    {
        $version = $pdo->query('SELECT VERSION()');
        $migrations = $this->migrator->status();
        return ['phpVersion' => PHP_VERSION, 'databaseVersion' => $version === false ? null : (string) $version->fetchColumn(),
            'migrations' => ['applied' => count(array_filter($migrations, static fn(array $row): bool => $row['state'] === 'applied')), 'total' => count($migrations),
                'latest' => $migrations === [] ? null : $migrations[array_key_last($migrations)]['version'],
                'pending' => array_values(array_map(static fn(array $row): string => $row['version'], array_filter($migrations, static fn(array $row): bool => $row['state'] !== 'applied')))],
            'maintenance' => $this->settings->maintenance, 'environment' => $this->settings->environment,
            'workspaceOrigins' => $this->settings->workspaceOrigins, 'sharePublicOrigin' => $this->settings->sharePublicOrigin,
            'shareKeyConfigured' => $this->urls->configured(),
            'githubTokenKeyId' => $this->settings->githubTokenKey === '' ? null : $this->settings->githubTokenKeyId];
    }

    /** Workspace sign-ins, link opens and new links per UTC day, oldest first, with empty days as zero.
     * @return list<array{day: string, workspaceLogins: int, linkOpens: int, linksCreated: int}>
     */
    private function series(PDO $pdo): array
    {
        $read = $pdo->prepare('SELECT day, metric, SUM(count) AS total FROM daily_metrics WHERE day > DATE_SUB(UTC_DATE(), INTERVAL ? DAY) GROUP BY day, metric');
        $read->execute([self::SERIES_DAYS]);
        $totals = [];
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string) $row['day']][(string) $row['metric']] = (int) $row['total'];
        }
        // The database clock decides "today", like the counters themselves.
        $today = $pdo->query('SELECT UTC_DATE()');
        $date = $today === false ? false : $today->fetchColumn();
        $end = new \DateTimeImmutable(is_string($date) ? $date : 'now', new \DateTimeZone('UTC'));
        $series = [];
        for ($offset = self::SERIES_DAYS - 1; $offset >= 0; $offset--) {
            $day = $end->modify('-' . $offset . ' day')->format('Y-m-d');
            $series[] = ['day' => $day, 'workspaceLogins' => $totals[$day]['workspace_login'] ?? 0, 'linkOpens' => $totals[$day]['link_opened'] ?? 0,
                'linksCreated' => $totals[$day]['link_created'] ?? 0];
        }
        return $series;
    }
}
