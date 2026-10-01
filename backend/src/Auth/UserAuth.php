<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\Username;
use Glimpse\Audit\AuditLog;
use Glimpse\Audit\DailyMetrics;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use PDO;
use SensitiveParameter;

/** Username/password sign-in and workspace sessions stored as token digests in SQL. */
final readonly class UserAuth
{
    public const AUDIENCE = 'workspace';
    public const TTL_SECONDS = 28800;
    public const GLOBAL_LIMIT = 300;
    public const ADDRESS_LIMIT = 20;
    public const USERNAME_LIMIT = 10;

    public function __construct(private Connection $connection, private AuditLog $audit, private DailyMetrics $metrics, private Sessions $sessions, private PasswordChange $passwords, private Throttle $throttle) {}

    /** Every failure reason (unknown name, wrong password, disabled account) returns the same response.
     * @return NewSession<UserContext>
     */
    public function login(string $username, #[SensitiveParameter] string $password, string $address, #[SensitiveParameter] string $previousToken = ''): NewSession
    {
        $this->limit('login:global', '', self::GLOBAL_LIMIT, null);
        $this->limit('login:address', $address, self::ADDRESS_LIMIT, null);
        try {
            $normalized = Username::normalize($username);
        } catch (Failure) {
            $normalized = null;
        }
        $pdo = $this->connection->get();
        $row = false;
        if ($normalized !== null) {
            $read = $pdo->prepare("SELECT u.id, u.username, u.password_hash, u.status, u.session_version, u.must_change_password,
                EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'member') AS member
                FROM users u WHERE u.username = ?");
            $read->execute([$normalized]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            // Unknown names are throttled too, so the response never reveals whether an account exists.
            $this->limit('login:username', $normalized, self::USERNAME_LIMIT, is_array($row) ? (int) $row['id'] : null);
        }
        $account = is_array($row) ? $row : null;
        $valid = Passwords::verify($password, $account === null ? null : (string) $account['password_hash']);
        if ($account === null || !$valid || $account['status'] !== 'active' || (int) $account['member'] !== 1) {
            $this->audit->record('login_failed', 'visitor', null, $account === null ? null : (int) $account['id'], [], 'failure', 'invalid_credentials');
            throw Failure::unauthenticated('invalid_credentials', 'Invalid username or password.');
        }
        $userId = (int) $account['id'];
        $this->throttle->clear('login:username', (string) $normalized);
        $rehash = Passwords::rehash($password, (string) $account['password_hash']);
        [$token, $sessionId] = Transaction::run($pdo, function () use ($pdo, $rehash, $userId, $account, $previousToken): array {
            if ($rehash !== null) {
                // Upgrading the algorithm or cost does not sign out other sessions and never overwrites a concurrent reset.
                $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND password_hash = ?')
                    ->execute([$rehash, $userId, $account['password_hash']]);
            }
            $token = $this->sessions->start($userId, (int) $account['session_version'], self::AUDIENCE, self::TTL_SECONDS, $previousToken);
            // The new session is the last row inserted.
            $sessionId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(6) WHERE id = ?')->execute([$userId]);
            $this->metrics->increment('workspace_login', $userId);
            $this->audit->record('login_succeeded', 'user', $userId, $userId, ['rehashed' => $rehash !== null], sessionAudience: self::AUDIENCE);
            return [$token, $sessionId];
        });
        return new NewSession($token, new UserContext(
            $userId,
            (string) $account['username'],
            $sessionId,
            (int) $account['session_version'],
            [UserContext::WORKSPACE],
            Csrf::token(self::AUDIENCE, $token),
            (bool) $account['must_change_password'],
        ));
    }

    /** The signed-in user replaces their password; every session ends and this browser continues with a new one.
     * @return NewSession<UserContext>
     */
    public function changePassword(UserContext $context, #[SensitiveParameter] string $current, #[SensitiveParameter] string $new, string $address): NewSession
    {
        $this->throttle->limit('password:user', (string) $context->userId, self::USERNAME_LIMIT);
        $this->throttle->limit('password:address', $address, self::ADDRESS_LIMIT);
        $token = $this->passwords->apply($context->userId, $current, $new, self::AUDIENCE, self::TTL_SECONDS);
        return new NewSession($token, $this->context($token) ?? self::unauthorized());
    }

    /** Checks the session, its expiry and revocation, the active account, its session version and workspace role on every request. */
    public function authenticate(#[SensitiveParameter] string $token): UserContext
    {
        return ($token === '' ? null : $this->context($token)) ?? self::unauthorized();
    }

    private function context(#[SensitiveParameter] string $token): ?UserContext
    {
        $read = $this->connection->get()->prepare("SELECT s.id, s.user_id, s.session_version, u.username, u.must_change_password FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token_digest = ? AND s.audience = ? AND " . Sessions::VALID_SQL . "
            AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'member')");
        $read->execute([hash('sha256', $token, true), self::AUDIENCE]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return new UserContext(
            (int) $row['user_id'],
            (string) $row['username'],
            (int) $row['id'],
            (int) $row['session_version'],
            [UserContext::WORKSPACE],
            Csrf::token(self::AUDIENCE, $token),
            (bool) $row['must_change_password'],
        );
    }

    public function logout(UserContext $context): void
    {
        $pdo = $this->connection->get();
        $pdo->prepare('DELETE FROM sessions WHERE id = ? AND user_id = ?')->execute([$context->sessionId, $context->userId]);
        $this->audit->record('logout', 'user', $context->userId, $context->userId, sessionAudience: self::AUDIENCE);
    }

    /** Only the request that first exceeds a limit is audited, so repeated attempts cannot flood the activity log. */
    private function limit(string $namespace, string $key, int $limit, ?int $subjectUserId): void
    {
        $attempts = $this->throttle->hit($namespace, $key);
        if ($attempts <= $limit) {
            return;
        }
        if ($attempts === $limit + 1) {
            $this->audit->record('login_throttled', 'visitor', null, $subjectUserId, ['scope' => substr($namespace, 6)], 'rejected', 'rate_limited');
        }
        throw Throttle::limited();
    }

    private static function unauthorized(): never
    {
        throw Failure::unauthenticated('unauthorized', 'Sign in to continue.');
    }
}
