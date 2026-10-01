<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\Username;
use Glimpse\Audit\DailyMetrics;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use PDO;
use SensitiveParameter;

/**
 * Admin panel sessions: the same accounts and passwords as the workspace, but a separate audience, cookie and CSRF scope,
 * so a workspace session never opens the admin API and an admin session never opens the workspace API.
 * Only active accounts with the platform_admin role sign in. The admin panel writes no audit events.
 */
final readonly class AdminAuth
{
    public const AUDIENCE = 'platform_admin';
    public const TTL_SECONDS = 28800;
    public const GLOBAL_LIMIT = 100;
    public const ADDRESS_LIMIT = 20;
    public const USERNAME_LIMIT = 5;

    public function __construct(private Connection $connection, private DailyMetrics $metrics, private Sessions $sessions, private PasswordChange $passwords, private Throttle $throttle) {}

    /** Every failure reason (unknown name, wrong password, disabled account, no admin role) returns the same response.
     * @return NewSession<AdminContext>
     */
    public function login(string $username, #[SensitiveParameter] string $password, string $address, #[SensitiveParameter] string $previousToken = ''): NewSession
    {
        $this->throttle->limit('admin_login:global', '', self::GLOBAL_LIMIT);
        $this->throttle->limit('admin_login:address', $address, self::ADDRESS_LIMIT);
        try {
            $normalized = Username::normalize($username);
        } catch (Failure) {
            $normalized = null;
        }
        $pdo = $this->connection->get();
        $account = null;
        if ($normalized !== null) {
            $read = $pdo->prepare("SELECT u.id, u.password_hash, u.status, u.session_version,
                EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'platform_admin') AS admin
                FROM users u WHERE u.username = ?");
            $read->execute([$normalized]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            $account = is_array($row) ? $row : null;
            // Unknown names are throttled too, so the response never reveals whether an account exists.
            $this->throttle->limit('admin_login:username', $normalized, self::USERNAME_LIMIT);
        }
        $valid = Passwords::verify($password, $account === null ? null : (string) $account['password_hash']);
        if ($account === null || !$valid || $account['status'] !== 'active' || (int) $account['admin'] !== 1) {
            throw Failure::unauthenticated('invalid_credentials', 'Invalid username or password.');
        }
        $userId = (int) $account['id'];
        $this->throttle->clear('admin_login:username', (string) $normalized);
        $rehash = Passwords::rehash($password, (string) $account['password_hash']);
        $token = Transaction::run($pdo, function () use ($pdo, $rehash, $userId, $account, $previousToken): string {
            if ($rehash !== null) {
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND password_hash = ?')
                    ->execute([$rehash, $userId, $account['password_hash']]);
            }
            $token = $this->sessions->start($userId, (int) $account['session_version'], self::AUDIENCE, self::TTL_SECONDS, $previousToken);
            $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$userId]);
            $this->metrics->increment('admin_login', $userId);
            return $token;
        });
        return new NewSession($token, $this->context($token) ?? throw Failure::unauthenticated('unauthorized', 'Sign in to continue.'));
    }

    /** Checks the admin session, its expiry and revocation, the active account, its session version and the admin role on every request. */
    public function authenticate(#[SensitiveParameter] string $token): AdminContext
    {
        return ($token === '' ? null : $this->context($token)) ?? throw Failure::unauthenticated('unauthorized', 'Sign in to continue.');
    }

    /** The signed-in administrator replaces their own password; every session ends and this browser continues with a new admin session.
     * @return NewSession<AdminContext>
     */
    public function changePassword(AdminContext $context, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, string $address): NewSession
    {
        $this->throttle->limit('password:user', (string) $context->userId, UserAuth::USERNAME_LIMIT);
        $this->throttle->limit('password:address', $address, UserAuth::ADDRESS_LIMIT);
        $token = $this->passwords->apply($context->userId, $current, $new, self::AUDIENCE, self::TTL_SECONDS);
        return new NewSession($token, $this->context($token) ?? throw Failure::unauthenticated('unauthorized', 'Sign in to continue.'));
    }

    public function logout(AdminContext $context): void
    {
        $this->connection->get()->prepare('DELETE FROM sessions WHERE id = ? AND user_id = ?')->execute([$context->sessionId, $context->userId]);
    }

    private function context(#[SensitiveParameter] string $token): ?AdminContext
    {
        $read = $this->connection->get()->prepare("SELECT s.id, s.user_id, s.session_version, u.username, u.first_name, u.last_name, u.email, u.must_change_password
            FROM sessions s JOIN users u ON u.id = s.user_id
            WHERE s.token_digest = ? AND s.audience = ? AND " . Sessions::VALID_SQL . "
            AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'platform_admin')");
        $read->execute([hash('sha256', $token, true), self::AUDIENCE]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return new AdminContext(
            (int) $row['user_id'],
            (string) $row['username'],
            $row['first_name'] === null ? null : (string) $row['first_name'],
            $row['last_name'] === null ? null : (string) $row['last_name'],
            $row['email'] === null ? null : (string) $row['email'],
            (int) $row['id'],
            (int) $row['session_version'],
            (bool) $row['must_change_password'],
            Csrf::token(self::AUDIENCE, $token),
        );
    }
}
