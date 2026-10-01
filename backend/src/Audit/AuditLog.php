<?php

declare(strict_types=1);

namespace Glimpse\Audit;

use Glimpse\Database\Connection;
use InvalidArgumentException;

/** Writes owner-aware audit events. Callers pass counts, versions and safe codes only: never passwords,
 * tokens or their prefixes, session tokens, share secrets or private path manifests.
 */
final readonly class AuditLog
{
    public function __construct(private Connection $connection) {}

    private const ACTORS = ['user', 'visitor', 'cli', 'system'];
    private const MAX_METADATA_BYTES = 2048;

    /** Actor "visitor" without a session audience is an unauthenticated web client, such as a failed sign-in.
     * @param array<string, int|bool|string|null> $metadata
     */
    public function record(
        string $action,
        string $actorKind,
        ?int $actorUserId,
        ?int $subjectUserId,
        array $metadata = [],
        string $outcome = 'success',
        ?string $errorCode = null,
        ?string $sessionAudience = null,
        ?int $repositoryId = null,
        ?int $shareLinkId = null,
    ): void {
        $pdo = $this->connection->get();
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $action) !== 1 || preg_match('/^[a-z0-9_]{1,32}$/D', $outcome) !== 1
            || !in_array($actorKind, self::ACTORS, true) || ($errorCode !== null && preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode) !== 1)
            || !in_array($sessionAudience, [null, 'workspace', 'visitor', 'platform_admin'], true)) {
            throw new InvalidArgumentException('Invalid audit event.');
        }
        $pdo->prepare('INSERT INTO audit_events (action, outcome, actor_kind, actor_user_id, subject_user_id, session_audience, error_code,
            repository_id, share_link_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')
            ->execute([$action, $outcome, $actorKind, $actorUserId, $subjectUserId, $sessionAudience, $errorCode, $repositoryId, $shareLinkId, self::metadata($metadata)]);
    }

    /** @param array<string, int|bool|string|null> $metadata */
    public static function metadata(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }
        if (count($metadata) > 16) {
            throw new InvalidArgumentException('Too much audit metadata.');
        }
        foreach ($metadata as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_]{1,40}$/D', $key) !== 1
                || (is_string($value) && (strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')))) {
                throw new InvalidArgumentException('Invalid audit metadata.');
            }
        }
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_METADATA_BYTES) {
            throw new InvalidArgumentException('Audit metadata is too large.');
        }
        return $json;
    }
}
