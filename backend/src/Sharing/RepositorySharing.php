<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Audit\AuditLog;
use Glimpse\Auth\UserAuth;
use Glimpse\Cache\PreviewCache;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryRegistry;
use Glimpse\Repositories\RepositoryServices;
use Glimpse\Source\ConnectionState;

/** Whether a repository can be shared at all: turning sharing off stops every link of the repository at once and clears its cache. */
final readonly class RepositorySharing
{
    public function __construct(
        private Connection $connection,
        private RepositoryServices $repositories,
        private RepositoryRegistry $registry,
        private AuditLog $audit,
        private PreviewCaches $caches,
    ) {}

    /** Disabling an existing repository works without reaching the platform, even when the connection is missing or unreadable;
     * the repository is then found on the instance it was registered on.
     */
    public function setActive(int $userId, RepositoryRef $ref, bool $active): void
    {
        $repositories = null;
        try {
            $repositories = $this->repositories->forUser($userId, $ref->provider);
        } catch (Failure $error) {
            if ($active) {
                throw $error;
            }
        }
        $pdo = $this->connection->get();
        $instance = $repositories?->instance() ?? $this->repositories->instance($userId, $ref->provider);
        $existingId = false;
        if ($instance !== null) {
            [$where, $parameters] = $ref->where($userId, $instance);
            $read = $pdo->prepare('SELECT id FROM repositories WHERE ' . $where);
            $read->execute($parameters);
            $existingId = $read->fetchColumn();
        }
        $metadata = null;
        if ($active || $existingId === false) {
            if ($repositories === null) {
                throw ConnectionState::notConnected($ref->provider);
            }
            $metadata = $repositories->metadata($ref);
        }
        Transaction::run($pdo, function () use ($metadata, $repositories, $existingId, $pdo, $active, $userId, $ref): void {
            $repoId = $metadata === null || $repositories === null ? (int) $existingId
                : $this->registry->register($metadata, $repositories->ownerId(), $repositories->provider(), $repositories->instance());
            $pdo->prepare('UPDATE repositories SET active = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ?')->execute([(int) $active, $repoId, $userId]);
            if (!$active) {
                $this->caches->invalidate($userId, $ref->provider, PreviewCache::scope($ref->provider, $ref->key()));
            }
            $this->audit->record($active ? 'sharing_enabled' : 'sharing_disabled', 'user', $userId, $userId, sessionAudience: UserAuth::AUDIENCE, repositoryId: $repoId);
        });
    }
}
