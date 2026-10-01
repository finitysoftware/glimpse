<?php

declare(strict_types=1);

namespace Glimpse\Accounts;

use Glimpse\Audit\AuditLog;
use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use LogicException;
use PDO;
use PDOException;
use SensitiveParameter;

/**
 * Account management shared by the private CLI and the admin panel. The by-name methods are the audited CLI operations;
 * the static by-ID operations run inside the caller's transaction, write no audit events and are composed by the admin panel.
 * Every change increments users.version, so concurrent edits can be detected.
 */
final readonly class UserProvisioningService
{
    public function __construct(private Connection $connection, private AuditLog $audit) {}

    public function create(string $username, #[SensitiveParameter] string $password, bool $platformAdmin = false): int
    {
        $normalized = Username::normalize($username);
        $hash = Passwords::hash($password);
        $pdo = $this->connection->get();
        // An existing username is a conflict and stays unchanged.
        return Transaction::run($pdo, function () use ($normalized, $hash, $platformAdmin): int {
            $id = $this->insertAccount($normalized, $hash, $platformAdmin);
            $this->audit->record('user_created', 'cli', null, $id, ['platformAdmin' => $platformAdmin]);
            return $id;
        });
    }

    /** Replaces the password and signs out every existing session of the account. */
    public function resetPassword(string $username, #[SensitiveParameter] string $password): void
    {
        Username::normalize($username);
        $hash = Passwords::hash($password);
        $this->change($username, function (PDO $pdo, int $id) use ($hash): array {
            $this->setPassword($id, $hash, false);
            return ['user_password_reset', []];
        });
    }

    public function disable(string $username): bool
    {
        return $this->change($username, fn(PDO $pdo, int $id): ?array => $this->setActive($id, false) ? ['user_disabled', []] : null);
    }

    public function enable(string $username): bool
    {
        return $this->change($username, fn(PDO $pdo, int $id): ?array => $this->setActive($id, true) ? ['user_enabled', []] : null);
    }

    public function grantPlatformAdmin(string $username): bool
    {
        return $this->change($username, fn(PDO $pdo, int $id): ?array =>
            $this->setPlatformAdmin($id, true) ? ['user_role_granted', ['role' => 'platform_admin']] : null);
    }

    /** Revoking a role also ends existing sessions, so no session keeps the removed privilege. */
    public function revokePlatformAdmin(string $username): bool
    {
        return $this->change($username, fn(PDO $pdo, int $id): ?array =>
            $this->setPlatformAdmin($id, false) ? ['user_role_revoked', ['role' => 'platform_admin']] : null);
    }

    /** @return list<array{username: string, status: string, roles: list<string>, createdAt: string, lastLoginAt: string|null}> */
    public function list(): array
    {
        $read = $this->connection->get()->query("SELECT u.username, u.status, u.created_at, u.last_login_at,
            GROUP_CONCAT(r.role ORDER BY r.role SEPARATOR ',') AS roles
            FROM users u LEFT JOIN user_roles r ON r.user_id = u.id
            GROUP BY u.id, u.username, u.status, u.created_at, u.last_login_at ORDER BY u.username");
        $users = [];
        foreach ($read === false ? [] : $read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $users[] = ['username' => (string) $row['username'], 'status' => (string) $row['status'],
                'roles' => $row['roles'] === null ? [] : explode(',', (string) $row['roles']),
                'createdAt' => Dates::iso((string) $row['created_at']),
                'lastLoginAt' => $row['last_login_at'] === null ? null : Dates::iso((string) $row['last_login_at'])];
        }
        return $users;
    }

    /** Inserts an active member (and optionally platform admin) account inside the caller's transaction, without an audit event.
     * @param array{firstName?: string|null, lastName?: string|null, email?: string|null} $profile Already validated values.
     */
    public function insertAccount(
        string $normalizedUsername,
        #[SensitiveParameter]
        string $passwordHash,
        bool $platformAdmin,
        array $profile = [],
        bool $mustChangePassword = false,
    ): int {
        $pdo = $this->connection->get();
        if (!$pdo->inTransaction()) {
            throw new LogicException('Account creation requires an active transaction.');
        }
        try {
            $pdo->prepare("INSERT INTO users (username, first_name, last_name, email, password_hash, status, session_version, version,
                password_changed_at, must_change_password, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'active', 1, 1, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
                ->execute([$normalizedUsername, $profile['firstName'] ?? null, $profile['lastName'] ?? null, $profile['email'] ?? null,
                    $passwordHash, (int) $mustChangePassword]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                if (str_contains((string) ($error->errorInfo[2] ?? ''), 'user_email')) {
                    throw Failure::conflict('email_taken', 'Another account already uses this email address.');
                }
                throw Failure::conflict('username_taken', 'An account with this username already exists. It was not changed.');
            }
            throw $error;
        }
        $id = (int) $pdo->lastInsertId();
        $roles = $pdo->prepare('INSERT INTO user_roles (user_id, role, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))');
        $roles->execute([$id, 'member']);
        if ($platformAdmin) {
            $roles->execute([$id, 'platform_admin']);
        }
        return $id;
    }

    /** Locks one account for a change in the caller's transaction.
     * @return array{id: int, username: string, status: string, version: int}
     */
    public function lock(int $id): array
    {
        $pdo = $this->connection->get();
        if (!$pdo->inTransaction()) {
            throw new LogicException('Account changes require an active transaction.');
        }
        $read = $pdo->prepare('SELECT id, username, status, version FROM users WHERE id = ? FOR UPDATE');
        $read->execute([$id]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw Failure::notFound('user_not_found', 'This account no longer exists.');
        }
        return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'status' => (string) $row['status'], 'version' => (int) $row['version']];
    }

    /** Sets a new password hash and ends every session of the account. */
    public function setPassword(int $id, #[SensitiveParameter] string $passwordHash, bool $mustChangePassword): void
    {
        $pdo = $this->connection->get();
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(6), must_change_password = ?,
            session_version = session_version + 1, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$passwordHash, (int) $mustChangePassword, $id]);
        $this->endSessions($id);
    }

    /** Disabling ends every session; the account's links stop serving on their next request. Returns false when nothing changed. */
    public function setActive(int $id, bool $active): bool
    {
        $pdo = $this->connection->get();
        $change = $pdo->prepare($active
            ? "UPDATE users SET status = 'active', version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND status = 'disabled'"
            : "UPDATE users SET status = 'disabled', session_version = session_version + 1, version = version + 1, updated_at = UTC_TIMESTAMP(6)
                WHERE id = ? AND status = 'active'", );
        $change->execute([$id]);
        if ($change->rowCount() !== 1) {
            return false;
        }
        if (!$active) {
            $this->endSessions($id);
        }
        return true;
    }

    /** Granting keeps sessions; revoking ends them so no session keeps the removed privilege. Returns false when nothing changed. */
    public function setPlatformAdmin(int $id, bool $platformAdmin): bool
    {
        $pdo = $this->connection->get();
        $change = $pdo->prepare($platformAdmin
            ? "INSERT IGNORE INTO user_roles (user_id, role, created_at) VALUES (?, 'platform_admin', UTC_TIMESTAMP(6))"
            : "DELETE FROM user_roles WHERE user_id = ? AND role = 'platform_admin'");
        $change->execute([$id]);
        if ($change->rowCount() !== 1) {
            return false;
        }
        $pdo->prepare('UPDATE users SET ' . ($platformAdmin ? '' : 'session_version = session_version + 1, ')
            . 'version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$id]);
        if (!$platformAdmin) {
            $this->endSessions($id);
        }
        return true;
    }

    /** Signs the account out everywhere (workspace and admin sessions). */
    public function revokeSessions(int $id): void
    {
        $pdo = $this->connection->get();
        $pdo->prepare('UPDATE users SET session_version = session_version + 1, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$id]);
        $this->endSessions($id);
    }

    /** @param callable(PDO, int, string): (array{0: string, 1: array<string, int|bool|string|null>}|null) $mutation */
    private function change(string $username, callable $mutation): bool
    {
        $normalized = Username::normalize($username);
        $pdo = $this->connection->get();
        return Transaction::run($pdo, function () use ($pdo, $normalized, $mutation): bool {
            $read = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $read->execute([$normalized]);
            $id = $read->fetchColumn();
            if ($id === false) {
                throw Failure::notFound('user_not_found', 'No account with this username exists.');
            }
            $account = $this->lock((int) $id);
            $event = $mutation($pdo, $account['id'], $account['status']);
            if ($event !== null) {
                $version = $pdo->prepare('SELECT session_version FROM users WHERE id = ?');
                $version->execute([$account['id']]);
                $this->audit->record($event[0], 'cli', null, $account['id'], $event[1] + ['sessionVersion' => (int) $version->fetchColumn()]);
            }
            return $event !== null;
        });
    }

    private function endSessions(int $userId): void
    {
        $pdo = $this->connection->get();
        $pdo->prepare('UPDATE sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND revoked_at IS NULL')->execute([$userId]);
    }

}
