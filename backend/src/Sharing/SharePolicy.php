<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\Repositories\FilePolicy;
use PDO;

/** The access rules of one link as read for the current visitor request: exactly one snapshot per allowed branch; a branch
 * without one is denied.
 */
final class SharePolicy implements FilePolicy
{
    /** @var array<string, array<string, true>> */
    private array $paths = [];

    /** @param array<string, array{id: int, mode: string}> $branches */
    public function __construct(
        private readonly Connection $connection,
        public readonly int $linkId,
        public readonly int $userId,
        private readonly int $version,
        private readonly array $branches,
    ) {}

    public function version(): int
    {
        return $this->version;
    }

    /** Visible regular file paths of a branch, or NULL when every file of the branch is visible. Loaded only for the requested branch.
     * @return array<string, true>|null
     */
    public function paths(string $branch): ?array
    {
        $policy = $this->branches[$branch] ?? throw Failure::forbidden('branch_not_allowed', 'This branch is not available through this link.');
        if ($policy['mode'] === 'all') {
            return null;
        }
        if (!isset($this->paths[$branch])) {
            $read = $this->connection->get()->prepare('SELECT path FROM share_visibility_files WHERE policy_id = ? AND user_id = ?');
            $read->execute([$policy['id'], $this->userId]);
            $paths = [];
            foreach ($read->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $paths[(string) $path] = true;
            }
            $this->paths[$branch] = $paths;
        }
        return $this->paths[$branch];
    }

    /** Checked again just before a visitor response leaves the server: a request started under older rules never returns
     * content that newer rules hide, and a revoked, expired or disabled link stops immediately.
     */
    public function assertCurrent(): void
    {
        $read = $this->connection->get()->prepare('SELECT l.policy_version FROM share_links l ' . VisitorAccess::SERVES_JOIN . '
            WHERE l.id = ? AND l.user_id = ? AND ' . VisitorAccess::SERVES_SQL);
        $read->execute([$this->linkId, $this->userId]);
        $version = $read->fetchColumn();
        if ($version === false) {
            throw UnavailableLink::failure();
        }
        if ((int) $version !== $this->version) {
            throw Failure::conflict('share_policy_changed', 'Access through this link has changed. Reload to see what it allows now.');
        }
    }
}
