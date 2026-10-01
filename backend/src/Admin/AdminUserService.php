<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\Username;
use Glimpse\Accounts\UserProvisioningService;
use Glimpse\Auth\AdminContext;
use Glimpse\Auth\Sessions;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\Page;
use Glimpse\Sharing\LinkStatus;
use PDO;
use PDOException;
use Throwable;

/**
 * Accounts as the admin panel manages them. Every change runs in one transaction with the account locked and its version checked;
 * an administrator cannot disable, delete or demote their own account, and at least one active administrator always remains.
 * Passwords are only ever set, never returned. The admin panel writes no audit events.
 */
final readonly class AdminUserService
{
    public const PAGE_SIZE = 50;

    public function __construct(private Connection $connection, private GitHubConnectionService $connections, private GitLabConnectionService $gitlab, private UserProvisioningService $accounts, private AccountPurge $purge, private PreviewCaches $caches) {}

    /** @return Page<AdminUser> */
    public function list(UserFilter $filter): Page
    {
        [$status, $role, $github, $gitlab, $sort, $search, $page]
            = [$filter->status, $filter->role, $filter->github, $filter->gitlab, $filter->sort, $filter->search, $filter->page];
        $where = ['1 = 1'];
        $params = [];
        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            // Username and email are ASCII columns; converting them lets one Unicode search term match every field.
            $where[] = "(CONVERT(u.username USING utf8mb4) LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONVERT(u.email USING utf8mb4) LIKE ?
                OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        if ($role !== '') {
            $where[] = ($role === 'admin' ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM user_roles a WHERE a.user_id = u.id AND a.role = 'platform_admin')";
        }
        if ($github === 'not_configured') {
            $where[] = 'c.id IS NULL';
        } elseif ($github !== '') {
            $where[] = 'c.last_check_status = ?';
            $params[] = $github;
        }
        if ($gitlab === 'not_configured') {
            $where[] = 'g.id IS NULL';
        } elseif ($gitlab !== '') {
            $where[] = 'g.last_check_status = ?';
            $params[] = $gitlab;
        }
        $order = match ($sort) {
            'username' => 'u.username',
            'created' => 'u.created_at DESC, u.id DESC',
            'last_sign_in' => 'u.last_login_at IS NULL, u.last_login_at DESC, u.username',
            default => "COALESCE(u.last_name, u.username), COALESCE(u.first_name, ''), u.username",
        };
        $pdo = $this->connection->get();
        $from = ' FROM users u ' . self::CONNECTIONS . ' WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $read = $pdo->prepare(self::SELECT . $from . ' ORDER BY ' . $order . ' LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $read->execute($params);
        $rows = $read->fetchAll(PDO::FETCH_ASSOC);
        $items = array_values(array_map(fn(array $row): AdminUser => self::item($row), array_slice($rows, 0, self::PAGE_SIZE)));
        return new Page($items, $page, count($rows) > self::PAGE_SIZE ? $page + 1 : null, (int) $count->fetchColumn());
    }

    public function requireAccount(int $id): void
    {
        $read = $this->connection->get()->prepare('SELECT 1 FROM users WHERE id = ?');
        $read->execute([$id]);
        if ($read->fetchColumn() === false) {
            throw Failure::notFound('user_not_found', 'This account no longer exists.');
        }
    }

    /** Everything the Edit dialog and the account summary show; never a password, token or share URL. */
    public function detail(int $id): AdminUserDetail
    {
        $pdo = $this->connection->get();
        $read = $pdo->prepare(self::SELECT . ', u.must_change_password, c.last_checked_at, c.last_error_code, c.credential_version,
            g.last_checked_at AS gitlab_checked_at, g.last_error_code AS gitlab_error, g.credential_version AS gitlab_version, g.token_expires_at AS gitlab_expires,
            (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.audience = \'workspace\' AND ' . Sessions::VALID_SQL . ') AS workspace_sessions,
            (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.audience = \'platform_admin\' AND ' . Sessions::VALID_SQL . ') AS admin_sessions,
            (SELECT COUNT(*) FROM repositories p WHERE p.user_id = u.id) AS repositories_total,
            (SELECT COUNT(*) FROM repositories p WHERE p.user_id = u.id AND p.active = 1) AS repositories_active,
            (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id AND l.revoked_at IS NOT NULL) AS revoked_links,
            (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id AND ' . LinkStatus::EXPIRED_SQL . ') AS expired_links,
            (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id AND ' . LinkStatus::ACTIVE_SQL . '
                AND l.allow_history = 1) AS history_links,
            (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id AND ' . LinkStatus::ACTIVE_SQL . '
                AND EXISTS (SELECT 1 FROM share_branch_policies b WHERE b.share_link_id = l.id AND b.mode = \'selected\')) AS selected_links,
            (SELECT COALESCE(SUM(m.count), 0) FROM daily_metrics m WHERE m.user_id = u.id AND m.metric = \'link_opened\'
                AND m.day > DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)) AS opens
            FROM users u ' . self::CONNECTIONS . ' WHERE u.id = ?');
        $read->execute([$id]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw Failure::notFound('user_not_found', 'This account no longer exists.');
        }
        $cache = $this->caches->status($id);
        $text = static fn(mixed $value): ?string => $value === null ? null : (string) $value;
        return new AdminUserDetail(
            self::item($row),
            (bool) $row['must_change_password'],
            ['workspace' => (int) $row['workspace_sessions'], 'admin' => (int) $row['admin_sessions']],
            ['active' => (int) $row['repositories_active'], 'total' => (int) $row['repositories_total']],
            ['active' => (int) $row['active_links'], 'expired' => (int) $row['expired_links'], 'revoked' => (int) $row['revoked_links'],
                'selectedFiles' => (int) $row['selected_links'], 'history' => (int) $row['history_links'], 'opens30Days' => (int) $row['opens']],
            ['configured' => $row['last_check_status'] !== null, 'status' => (string) ($row['last_check_status'] ?? 'not_configured'),
                'login' => $text($row['github_login']), 'lastCheckedAt' => Dates::isoOrNull($row['last_checked_at']), 'errorCode' => $text($row['last_error_code']),
                'credentialVersion' => (int) ($row['credential_version'] ?? 0)],
            ['configured' => $row['gitlab_status'] !== null, 'status' => (string) ($row['gitlab_status'] ?? 'not_configured'),
                'username' => $text($row['gitlab_username']), 'baseUrl' => $text($row['gitlab_url']), 'tokenExpiresAt' => $text($row['gitlab_expires']),
                'lastCheckedAt' => Dates::isoOrNull($row['gitlab_checked_at']), 'errorCode' => $text($row['gitlab_error']), 'credentialVersion' => (int) ($row['gitlab_version'] ?? 0)],
            ['entries' => $cache->entries, 'bytes' => $cache->bytes, 'upstreamCalls' => $cache->upstreamCalls],
        );
    }

    /** Creates an active account; optional GitHub and GitLab tokens are verified and saved for it, and a rejected token creates nothing. */
    public function create(NewAccount $account, string $address): AdminUserDetail
    {
        $profile = ['firstName' => $account->firstName, 'lastName' => $account->lastName, 'email' => $account->email];
        $token = $account->githubToken;
        $gitlabUrl = $account->gitlabUrl;
        $gitlabToken = $account->gitlabToken;
        $hash = Passwords::hash($account->password);
        $pdo = $this->connection->get();
        $id = Transaction::run($pdo, fn(): int => $this->accounts->insertAccount(
            $account->username,
            $hash,
            $account->admin,
            $profile,
            $account->mustChangePassword,
        ));
        try {
            if ($token !== null && trim($token) !== '') {
                $this->connections->replaceFor($id, trim($token), 0, $address);
            }
            if (is_string($gitlabToken) && trim($gitlabToken) !== '' && is_string($gitlabUrl)) {
                $this->gitlab->replaceFor($id, $gitlabUrl, trim($gitlabToken), 0, $address);
            }
        } catch (Throwable $error) {
            $this->purge($id);
            throw $error;
        }
        return $this->detail($id);
    }

    public function update(AdminContext $admin, int $id, AccountChanges $changes): AdminUserDetail
    {
        $expected = $changes->expectedVersion;
        $profile = $changes->profile;
        $hash = $changes->password === '' ? null : Passwords::hash($changes->password);
        $mustChange = $changes->mustChangePassword;
        $makeAdmin = $changes->admin;
        $active = $changes->active;
        if ($id === $admin->userId && ($active === false || $makeAdmin === false)) {
            self::selfForbidden();
        }

        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($active, $makeAdmin, $pdo, $id, $expected, $profile, $hash, $mustChange): void {
            if ($active === false || $makeAdmin === false) {
                $this->lockAdmins($pdo);
            }
            $account = $this->accounts->lock($id);
            if ($account['version'] !== $expected) {
                self::changed();
            }
            if (($active === false || $makeAdmin === false) && $this->isActiveAdmin($pdo, $id)) {
                self::requireOtherAdmin($pdo, $id);
            }
            if ($profile !== []) {
                $columns = implode(', ', array_map(fn(string $column): string => $column . ' = ?', array_keys($profile)));
                try {
                    $pdo->prepare('UPDATE users SET ' . $columns . ', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
                        ->execute([...array_values($profile), $id]);
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 1062) {
                        throw $error;
                    }
                    throw str_contains((string) ($error->errorInfo[2] ?? ''), 'user_email')
                        ? Failure::conflict('email_taken', 'Another account already uses this email address.')
                        : Failure::conflict('username_taken', 'An account with this username already exists.');
                }
            }
            if ($hash !== null) {
                $this->accounts->setPassword($id, $hash, $mustChange ?? false);
            } elseif ($mustChange !== null) {
                $pdo->prepare('UPDATE users SET must_change_password = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND must_change_password <> ?')
                    ->execute([(int) $mustChange, $id, (int) $mustChange]);
            }
            if ($makeAdmin !== null) {
                $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role, created_at) VALUES (?, 'member', UTC_TIMESTAMP(6))")->execute([$id]);
                $this->accounts->setPlatformAdmin($id, $makeAdmin);
            }
            if ($active !== null) {
                $this->accounts->setActive($id, $active);
            }
        });
        return $this->detail($id);
    }

    /** Signs the account out of the workspace and the admin panel on every device. */
    public function revokeSessions(int $id): AdminUserDetail
    {
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($id): void {
            $this->accounts->lock($id);
            $this->accounts->revokeSessions($id);
        });
        return $this->detail($id);
    }

    /** Permanently deletes the account and everything related to it: token, repositories, links, profiles, cache, sessions, history. */
    public function delete(AdminContext $admin, int $id, int $expected): void
    {
        if ($id === $admin->userId) {
            self::selfForbidden();
        }
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($pdo, $id, $expected): void {
            $this->lockAdmins($pdo);
            $account = $this->accounts->lock($id);
            if ($account['version'] !== $expected) {
                self::changed();
            }
            if ($this->isActiveAdmin($pdo, $id)) {
                self::requireOtherAdmin($pdo, $id);
            }
            // Disabled first: its links and sessions stop at once, even if the removal below has to be retried.
            $this->accounts->setActive($id, false);
        });
        $this->purge($id);
    }

    private function purge(int $id): void
    {
        $pdo = $this->connection->get();
        // Through the cache service, so the shared cache budget is released together with the entries.
        $this->caches->invalidateUser($id);
        Transaction::run($pdo, function () use ($id): void {
            $this->purge->delete($id);
        });
    }

    /** Locks every administrator account in a fixed order before any of them can lose the role, so concurrent demotions cannot leave none. */
    private function lockAdmins(PDO $pdo): void
    {
        $read = $pdo->query("SELECT u.id FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'platform_admin' ORDER BY u.id FOR UPDATE");
        if ($read !== false) {
            $read->fetchAll();
        }
    }

    private function isActiveAdmin(PDO $pdo, int $id): bool
    {
        $read = $pdo->prepare("SELECT 1 FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'platform_admin' WHERE u.id = ? AND u.status = 'active'");
        $read->execute([$id]);
        return $read->fetchColumn() !== false;
    }

    private static function requireOtherAdmin(PDO $pdo, int $id): void
    {
        $read = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'platform_admin' WHERE u.status = 'active' AND u.id <> ?");
        $read->execute([$id]);
        if ((int) $read->fetchColumn() === 0) {
            throw Failure::conflict('last_admin', 'At least one active administrator must remain.');
        }
    }

    private const SELECT = "SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.status, u.version, u.created_at, u.last_login_at,
        EXISTS (SELECT 1 FROM user_roles a WHERE a.user_id = u.id AND a.role = 'platform_admin') AS is_admin,
        c.last_check_status, c.github_login, g.last_check_status AS gitlab_status, g.gitlab_username, g.base_url AS gitlab_url,
        (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id AND " . LinkStatus::ACTIVE_SQL . ") AS active_links,
        (SELECT COUNT(*) FROM share_links l WHERE l.user_id = u.id) AS total_links";

    private const CONNECTIONS = 'LEFT JOIN github_connections c ON c.user_id = u.id LEFT JOIN gitlab_connections g ON g.user_id = u.id';

    /** @param array<array-key, mixed> $row */
    private static function item(array $row): AdminUser
    {
        return new AdminUser(
            (int) $row['id'],
            (string) $row['username'],
            $row['first_name'] === null ? null : (string) $row['first_name'],
            $row['last_name'] === null ? null : (string) $row['last_name'],
            $row['email'] === null ? null : (string) $row['email'],
            (bool) $row['is_admin'] ? 'admin' : 'user',
            $row['status'] === 'active',
            (int) $row['version'],
            ['status' => $row['last_check_status'] === null ? 'not_configured' : (string) $row['last_check_status'],
                'login' => $row['github_login'] === null ? null : (string) $row['github_login']],
            ['status' => $row['gitlab_status'] === null ? 'not_configured' : (string) $row['gitlab_status'],
                'username' => $row['gitlab_username'] === null ? null : (string) $row['gitlab_username'],
                'baseUrl' => $row['gitlab_url'] === null ? null : (string) $row['gitlab_url']],
            ['active' => (int) $row['active_links'], 'total' => (int) $row['total_links']],
            Dates::isoOrNull($row['last_login_at']),
            Dates::isoOrNull($row['created_at']),
        );
    }

    private static function selfForbidden(): never
    {
        throw Failure::rejected('self_action_forbidden', 'You cannot disable, delete or remove the administrator role from your own account.');
    }

    private static function changed(): never
    {
        throw Failure::conflict('user_changed', 'This account was changed in the meantime. Reload it and try again.');
    }
}
