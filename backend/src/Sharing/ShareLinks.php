<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Audit\AuditLog;
use Glimpse\Audit\DailyMetrics;
use Glimpse\Auth\UserAuth;
use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\Repositories\BrowseAccess;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryRegistry;
use Glimpse\Repositories\RepositoryServices;
use Glimpse\Source\Provider;
use PDO;

/** The owner's access links. Every query is bound to the owner's user ID; another user's link or repository is indistinguishable from a missing one. */
final readonly class ShareLinks
{
    public function __construct(
        private Connection $connection,
        private ShareUrls $urls,
        private RepositoryServices $repositories,
        private LinkPolicies $policies,
        private AuditLog $audit,
        private DailyMetrics $metrics,
        private RepositoryRegistry $registry,
    ) {}

    /** @return array{url: string, publicId: string} */
    public function create(int $userId, RepositoryRef $ref, NewShare $share): array
    {
        $repositories = $this->repositories->forUser($userId, $ref->provider);
        $url = $this->urls->issue();
        $metadata = $repositories->metadata($ref);
        $branches = $share->branches ?? BrowseAccess::selected([$metadata['defaultBranch']]);
        $repositories->validateBranches($ref, $branches, $metadata['id']);
        $days = $share->expiresInDays;
        $expires = $days === null ? null : gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($repositories, $metadata, $pdo, $userId, $share, $branches, $url, $expires): void {
            $repoId = $this->registry->register($metadata, $repositories->ownerId(), $repositories->provider(), $repositories->instance());
            $read = $pdo->prepare('SELECT active FROM repositories WHERE id = ? AND user_id = ? FOR UPDATE');
            $read->execute([$repoId, $userId]);
            if (!(bool) $read->fetchColumn()) {
                throw Failure::conflict('repository_disabled', 'Enable sharing for this repository first.');
            }
            $entries = LinkPolicies::choose($share->visibility, $branches, []);
            // New links always carry explicit per-branch rules.
            $pdo->prepare("INSERT INTO share_links (user_id, public_id, repository_id, name, secret_digest, encrypted_secret, expires_at, allowed_branches, allow_history,
                visibility_mode, visibility_schema_version, policy_version, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'branch_policies', 1, 1, UTC_TIMESTAMP(6))")
                ->execute([$userId, $url['publicId'], $repoId, $share->name, $url['digest'], $url['encryptedSecret'], $expires, json_encode($branches, JSON_THROW_ON_ERROR), (int) $share->allowHistory]);
            $linkId = (int) $pdo->lastInsertId();
            $this->policies->snapshot($userId, $repoId, $linkId, $entries, $branches);
            $this->audit('share_created', $userId, $repoId, $linkId);
            $this->metrics->increment('link_created', $userId);
        });
        return ['url' => $url['url'], 'publicId' => $url['publicId']];
    }

    /** Links of one repository on the owner's current instance of its platform; without a connection there are none to show. */
    public function listing(int $userId, RepositoryRef $ref, int $page): ShareListing
    {
        if ($page < 1 || $page > 10000) {
            throw Failure::invalid('invalid_page', 'Invalid link page.');
        }
        $instance = $this->repositories->instance($userId, $ref->provider);
        $repo = null;
        if ($instance !== null) {
            [$where, $parameters] = $ref->where($userId, $instance);
            $read = $this->connection->get()->prepare('SELECT id, active FROM repositories WHERE ' . $where);
            $read->execute($parameters);
            $repo = $read->fetch(PDO::FETCH_ASSOC);
        }
        $storageConfigured = $this->urls->configured();
        if (!is_array($repo)) {
            return new ShareListing([], true, null, $storageConfigured);
        }
        $links = $this->connection->get()->prepare("SELECT id, public_id, name, created_at, expires_at, revoked_at, allowed_branches, allow_history, policy_version,
            " . LinkStatus::STATUS_SQL . " AS status
            FROM share_links l WHERE l.user_id = ? AND l.repository_id = ? ORDER BY l.id DESC LIMIT 51 OFFSET " . (($page - 1) * 50));
        $links->execute([$userId, (int) $repo['id']]);
        $rows = $links->fetchAll(PDO::FETCH_ASSOC);
        $policies = $this->policies->forLinks($userId, array_values(array_map(fn(array $row): int => (int) $row['id'], array_slice($rows, 0, 50))));
        $items = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            $items[] = new ShareLink(
                (string) $row['public_id'],
                (string) $row['name'],
                Dates::iso((string) $row['created_at']),
                $row['expires_at'] === null ? null : Dates::iso((string) $row['expires_at']),
                LinkStatus::from((string) $row['status']),
                BrowseAccess::stored((string) $row['allowed_branches']),
                (bool) $row['allow_history'],
                (int) $row['policy_version'],
                $policies[(int) $row['id']] ?? [],
            );
        }
        return new ShareListing($items, (bool) $repo['active'], count($rows) > 50 ? $page + 1 : null, $storageConfigured);
    }

    /** @return array{url: string} */
    public function reveal(int $userId, string $publicId): array
    {
        $pdo = $this->connection->get();
        return Transaction::run($pdo, function () use ($pdo, $publicId, $userId): array {
            $read = $pdo->prepare('SELECT l.id, l.repository_id, l.encrypted_secret, l.secret_digest FROM share_links l
                JOIN repositories r ON r.id = l.repository_id AND r.user_id = l.user_id WHERE l.public_id = ? AND l.user_id = ? AND r.active = 1
                AND ' . LinkStatus::ACTIVE_SQL . ' FOR UPDATE');
            $read->execute([$publicId, $userId]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw UnavailableLink::failure();
            }
            $url = $this->urls->recover($publicId, (string) $row['encrypted_secret'], (string) $row['secret_digest']);
            $this->audit('share_url_revealed', $userId, (int) $row['repository_id'], (int) $row['id']);
            return ['url' => $url];
        });
    }

    public function revoke(int $userId, string $publicId): void
    {
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($userId, $publicId, $pdo): void {
            $row = $this->ownedLink($userId, $publicId);
            $pdo->prepare('UPDATE share_links SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)), encrypted_secret = NULL WHERE id = ? AND user_id = ?')->execute([$row['id'], $userId]);
            $this->audit('share_revoked', $userId, $row['repositoryId'], $row['id']);
        });
    }

    public function delete(int $userId, string $publicId): void
    {
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($userId, $publicId, $pdo): void {
            $row = $this->ownedLink($userId, $publicId);
            if (!$row['revoked']) {
                throw Failure::conflict('share_not_revoked', 'Revoke this link before deleting it.');
            }
            $this->audit('share_deleted', $userId, $row['repositoryId'], $row['id']);
            // Existing foreign keys remove this link's grants and policies, and preserve audit events with a NULL link ID.
            $pdo->prepare('DELETE FROM share_links WHERE id = ? AND user_id = ?')->execute([$row['id'], $userId]);
        });
    }

    /** Changes branches, History and file visibility together. Existing rules are kept only where the owner says so.
     * The URL and secret stay the same.
     */
    public function permissions(int $userId, string $publicId, LinkPermissions $permissions): void
    {
        $branches = $permissions->branches;
        $row = $this->activeLink($userId, $publicId);
        // The link's platform is known only once the link is read.
        $repositories = $this->repositories->forUser($userId, $row['provider']);
        // A link of another instance than the current connection cannot be checked against it.
        if ($repositories->instance() !== $row['instance']) {
            throw self::otherInstance();
        }
        $repositories->validateBranches($row['ref'], $branches, $row['externalId']);
        $this->change($userId, $row['id'], $permissions->expectedPolicyVersion, function (PDO $pdo, array $link) use ($userId, $branches, $permissions): string {
            $entries = LinkPolicies::choose($permissions->visibility, $branches, $this->policies->branches($userId, $link['id']));
            $this->policies->snapshot($userId, $link['repositoryId'], $link['id'], $entries, $branches);
            $pdo->prepare("UPDATE share_links SET allowed_branches = ?, allow_history = ?, visibility_mode = 'branch_policies', visibility_schema_version = 1,
                policy_version = policy_version + 1 WHERE id = ? AND user_id = ?")->execute([json_encode($branches, JSON_THROW_ON_ERROR), (int) $permissions->allowHistory, $link['id'], $userId]);
            return 'share_permissions_updated';
        });
    }

    /** Applies the current saved visibility of some branches to one link, keeping its branches, History, URL and secret. */
    public function applyVisibility(int $userId, string $publicId, VisibilityUpdate $update): void
    {
        $row = $this->activeLink($userId, $publicId);
        $this->change($userId, $row['id'], $update->expectedPolicyVersion, function (PDO $pdo, array $link) use ($userId, $update): string {
            $chosen = array_values(array_filter(array_map(fn(VisibilityChoice $choice): string => $choice->branch, $update->visibility)));
            if (array_diff($chosen, $link['branches']) !== []) {
                throw Failure::invalid('invalid_visibility', 'Apply file visibility only to branches this link allows.');
            }
            // Branches that are not named keep their current rules.
            $keep = array_map(fn(string $branch): VisibilityChoice => VisibilityChoice::keep($branch), array_values(array_diff($link['branches'], $chosen)));
            $entries = LinkPolicies::choose([...$update->visibility, ...$keep], $link['branches'], $this->policies->branches($userId, $link['id']));
            $this->policies->snapshot($userId, $link['repositoryId'], $link['id'], $entries, $link['branches']);
            $pdo->prepare('UPDATE share_links SET policy_version = policy_version + 1 WHERE id = ? AND user_id = ?')->execute([$link['id'], $userId]);
            return 'share_visibility_applied';
        });
    }

    /** @return array{id: int, repositoryId: int, revoked: bool} */
    private function ownedLink(int $userId, string $publicId): array
    {
        $read = $this->connection->get()->prepare('SELECT id, repository_id, revoked_at FROM share_links WHERE public_id = ? AND user_id = ? FOR UPDATE');
        $read->execute([$publicId, $userId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw UnavailableLink::failure();
        }
        return ['id' => (int) $row['id'], 'repositoryId' => (int) $row['repository_id'], 'revoked' => $row['revoked_at'] !== null];
    }

    /** @return array{id: int, provider: Provider, instance: string, ref: RepositoryRef, externalId: int} */
    private function activeLink(int $userId, string $publicId): array
    {
        $read = $this->connection->get()->prepare('SELECT l.id, r.provider, r.instance_url, r.owner, r.name, r.external_id FROM share_links l
            JOIN repositories r ON r.id = l.repository_id AND r.user_id = l.user_id WHERE l.public_id = ? AND l.user_id = ? AND l.revoked_at IS NULL');
        $read->execute([$publicId, $userId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw UnavailableLink::failure();
        }
        $ref = RepositoryRef::fromRow($row);
        return ['id' => (int) $row['id'], 'provider' => $ref->provider, 'instance' => (string) $row['instance_url'], 'ref' => $ref, 'externalId' => (int) $row['external_id']];
    }

    private static function otherInstance(): Failure
    {
        return Failure::conflict('repository_instance_changed', 'This link belongs to another GitLab instance than your current connection. Connect that instance again to change it.');
    }

    /** Runs one policy change in a transaction on the locked link; a stale expected policy version never overwrites newer rules.
     * @param callable(PDO, array{id: int, repositoryId: int, branches: list<string>, history: bool}): string $mutation
     */
    private function change(int $userId, int $linkId, ?int $expected, callable $mutation): void
    {
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($pdo, $linkId, $userId, $expected, $mutation): void {
            $read = $pdo->prepare('SELECT repository_id, revoked_at, policy_version, allowed_branches, allow_history FROM share_links WHERE id = ? AND user_id = ? FOR UPDATE');
            $read->execute([$linkId, $userId]);
            $link = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($link) || $link['revoked_at'] !== null) {
                throw UnavailableLink::failure();
            }
            if ($expected !== null && $expected !== (int) $link['policy_version']) {
                throw Failure::conflict('share_policy_changed', 'This link was changed elsewhere. Reload it before saving again.');
            }
            $action = $mutation($pdo, ['id' => $linkId, 'repositoryId' => (int) $link['repository_id'],
                'branches' => BrowseAccess::stored((string) $link['allowed_branches']), 'history' => (bool) $link['allow_history']]);
            $this->audit($action, $userId, (int) $link['repository_id'], $linkId);
        });
    }

    private function audit(string $action, int $userId, int $repoId, ?int $linkId): void
    {
        $this->audit->record(
            $action,
            'user',
            $userId,
            $userId,
            sessionAudience: UserAuth::AUDIENCE,
            repositoryId: $repoId,
            shareLinkId: $linkId,
        );
    }
}
