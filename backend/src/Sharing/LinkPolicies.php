<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Database\Connection;
use Glimpse\Failure;
use PDO;

/**
 * The file rules of access links, one per allowed branch: chosen when a link is saved, copied from the branch's saved
 * visibility profile, and read for the link lists and for visitors. Writes run inside the caller's transaction on the locked link.
 */
final readonly class LinkPolicies
{
    public function __construct(private Connection $connection) {}

    /** One explicit rule per allowed branch: the saved profile at a known version, all files, or (when editing) the link's current snapshot.
     * @param list<VisibilityChoice>|null $choices
     * @param list<string> $branches
     * @param list<string> $existing Branches that already have a snapshot on this link.
     * @return list<array{branch: string, kind: string, version: int}>
     */
    public static function choose(?array $choices, array $branches, array $existing): array
    {
        if ($choices === null) {
            throw Failure::invalid('visibility_required', 'Choose file visibility for every branch of this link: its saved rules or all files.');
        }
        $entries = [];
        foreach ($choices as $choice) {
            $branch = $choice->branch;
            if (!in_array($branch, $branches, true) || isset($entries[$branch])) {
                throw Failure::invalid('invalid_visibility', 'Each branch of the link needs exactly one file visibility choice.');
            }
            $entries[$branch] = match (true) {
                $choice->profileVersion !== null => ['branch' => $branch, 'kind' => 'profile', 'version' => $choice->profileVersion],
                $choice->allFiles => ['branch' => $branch, 'kind' => 'all', 'version' => 0],
                $choice->keep && in_array($branch, $existing, true) => ['branch' => $branch, 'kind' => 'keep', 'version' => 0],
                default => throw Failure::invalid('invalid_visibility', 'Choose the saved file visibility or all files for ' . $branch . '.'),
            };
        }
        if (count($entries) !== count($branches)) {
            throw Failure::invalid('visibility_required', 'Choose file visibility for every branch of this link: its saved rules or all files.');
        }
        return array_values($entries);
    }

    /** Copies profiles into the link atomically. Later profile edits never change this snapshot; removed branches lose their rules.
     * @param list<array{branch: string, kind: string, version: int}> $entries
     * @param list<string> $branches
     */
    public function snapshot(int $userId, int $repoId, int $linkId, array $entries, array $branches): void
    {
        $pdo = $this->connection->get();
        $remove = $pdo->prepare('DELETE FROM share_branch_policies WHERE share_link_id = ? AND user_id = ? AND branch = ?');
        foreach ($this->branches($userId, $linkId) as $branch) {
            if (!in_array($branch, $branches, true)) {
                $remove->execute([$linkId, $userId, $branch]);
            }
        }
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'keep') {
                continue;
            }
            $remove->execute([$linkId, $userId, $entry['branch']]);
            if ($entry['kind'] === 'all') {
                $pdo->prepare("INSERT INTO share_branch_policies (user_id, share_link_id, branch, mode, selected_count, created_at, updated_at)
                    VALUES (?, ?, ?, 'all', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$userId, $linkId, $entry['branch']]);
                continue;
            }
            $profile = $pdo->prepare('SELECT id, mode, version, source_sha, selected_count FROM repository_visibility_profiles
                WHERE user_id = ? AND repository_id = ? AND branch = ? LOCK IN SHARE MODE');
            $profile->execute([$userId, $repoId, $entry['branch']]);
            $saved = $profile->fetch(PDO::FETCH_ASSOC);
            if (!is_array($saved) || (int) $saved['version'] !== $entry['version']) {
                throw Failure::conflict('visibility_profile_changed', 'The saved file visibility of ' . $entry['branch'] . ' changed or is not saved. Review it and try again.');
            }
            if ($saved['mode'] === 'selected' && (int) $saved['selected_count'] === 0) {
                throw Failure::rejected('visibility_empty', 'No files are selected on ' . $entry['branch'] . '. Select files or share all files in this branch.');
            }
            $pdo->prepare('INSERT INTO share_branch_policies (user_id, share_link_id, branch, mode, source_profile_id, source_profile_version, source_sha, selected_count, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
                ->execute([$userId, $linkId, $entry['branch'], $saved['mode'], (int) $saved['id'], (int) $saved['version'], $saved['source_sha'], (int) $saved['selected_count']]);
            if ($saved['mode'] === 'selected') {
                $pdo->prepare('INSERT INTO share_visibility_files (policy_id, user_id, path, path_hash)
                    SELECT ?, user_id, path, path_hash FROM repository_visibility_files WHERE profile_id = ? AND user_id = ?')
                    ->execute([(int) $pdo->lastInsertId(), (int) $saved['id'], $userId]);
            }
        }
    }

    /** Branches that have a rule on this link.
     * @return list<string>
     */
    public function branches(int $userId, int $linkId): array
    {
        $read = $this->connection->get()->prepare('SELECT branch FROM share_branch_policies WHERE share_link_id = ? AND user_id = ?');
        $read->execute([$linkId, $userId]);
        return array_values(array_map('strval', $read->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** The rules of one link as a visitor request reads them, keyed by branch.
     * @return array<string, array{id: int, mode: string}>
     */
    public function forVisitor(int $userId, int $linkId): array
    {
        $read = $this->connection->get()->prepare('SELECT id, branch, mode FROM share_branch_policies WHERE share_link_id = ? AND user_id = ?');
        $read->execute([$linkId, $userId]);
        $policies = [];
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $policy) {
            $policies[(string) $policy['branch']] = ['id' => (int) $policy['id'], 'mode' => (string) $policy['mode']];
        }
        return $policies;
    }

    /** The rules of several links for a list, keyed by link ID; NULL $userId reads the links of every owner (admin panel).
     * @param list<int> $linkIds
     * @return array<int, list<BranchPolicy>>
     */
    public function forLinks(?int $userId, array $linkIds): array
    {
        if ($linkIds === []) {
            return [];
        }
        $owner = $userId === null ? '' : 'user_id = ? AND ';
        $read = $this->connection->get()->prepare('SELECT share_link_id, branch, mode, selected_count, source_profile_version FROM share_branch_policies
            WHERE ' . $owner . 'share_link_id IN (' . implode(', ', array_fill(0, count($linkIds), '?')) . ') ORDER BY branch');
        $read->execute($userId === null ? $linkIds : [$userId, ...$linkIds]);
        $policies = [];
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $policies[(int) $row['share_link_id']][] = new BranchPolicy(
                (string) $row['branch'],
                (string) $row['mode'],
                (int) $row['selected_count'],
                $row['source_profile_version'] === null ? null : (int) $row['source_profile_version'],
            );
        }
        return $policies;
    }
}
