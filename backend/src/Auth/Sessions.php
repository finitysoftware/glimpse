<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use Glimpse\Database\Connection;
use SensitiveParameter;

/** Signed-in sessions of one audience; SQL keeps only the digest of the random token. */
final readonly class Sessions
{
    /**
     * SQL on sessions `s` of accounts `u`: a signed-in session that still counts - not revoked, not expired, of an active account
     * whose session version has not moved on (a password change, a disabled account or Sign out everywhere end older sessions).
     */
    public const VALID_SQL = "s.kind = 'user' AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND u.status = 'active' AND u.session_version = s.session_version";

    public function __construct(private Connection $connection) {}

    /**
     * The session steps of a successful sign-in, in the caller's transaction: the session this browser held in the same audience
     * ends, expired and revoked sessions of the account are removed, and a new session is issued. Returns its token.
     */
    public function start(int $userId, int $sessionVersion, string $audience, int $ttlSeconds, #[SensitiveParameter] string $previousToken): string
    {
        $pdo = $this->connection->get();
        if ($previousToken !== '') {
            $pdo->prepare("DELETE FROM sessions WHERE token_digest = ? AND kind = 'user' AND audience = ?")->execute([hash('sha256', $previousToken, true), $audience]);
        }
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND (expires_at <= UTC_TIMESTAMP(6) OR revoked_at IS NOT NULL)')->execute([$userId]);
        return $this->issue($userId, $sessionVersion, $audience, $ttlSeconds);
    }

    /** Inserts a new session for the account's current session version and returns its token. */
    public function issue(int $userId, int $sessionVersion, string $audience, int $ttlSeconds): string
    {
        $pdo = $this->connection->get();
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO sessions (token_digest, kind, user_id, session_version, audience, created_at, expires_at)
            VALUES (?, 'user', ?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL " . $ttlSeconds . ' SECOND))')
            ->execute([hash('sha256', $token, true), $userId, $sessionVersion, $audience]);
        return $token;
    }
}
