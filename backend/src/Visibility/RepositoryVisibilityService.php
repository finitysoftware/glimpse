<?php

declare(strict_types=1);

namespace Glimpse\Visibility;

use Glimpse\Audit\AuditLog;
use Glimpse\Auth\UserAuth;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryRegistry;
use Glimpse\Repositories\RepositoryServices;
use Glimpse\Source\BranchName;
use PDO;

/** Per-branch visibility profiles used as templates for new links. Selections are explicit lists of regular file paths;
 * a folder selection selects the folder's current files and is never a wildcard for future files.
 */
final readonly class RepositoryVisibilityService
{
    public const MAX_PATHS = 5000;
    public const MAX_TOTAL_BYTES = 1048576;
    public const MAX_MANIFEST_FILES = 20000;

    public function __construct(private Connection $connection, private RepositoryServices $repositories, private AuditLog $audit, private RepositoryRegistry $registry) {}

    public function profile(int $userId, RepositoryRef $ref, string $branch): VisibilityProfile
    {
        $repositories = $this->repositories->forUser($userId, $ref->provider);
        $state = $repositories->branchState($ref, $branch, false);
        $manifest = $repositories->manifest($ref, $state['metadata']['id'], $state['head']);
        $pdo = $this->connection->get();
        $repoId = $this->registry->find($userId, $repositories->provider(), $repositories->instance(), $state['metadata']['id']);
        $profile = $repoId === null ? null : self::load($pdo, $userId, $repoId, $branch, false);
        return self::dto($branch, $state['head'], $manifest, $profile);
    }

    /** Files named in a summary so the link form can show what a selection shares. */
    private const SUMMARY_PREVIEW = 5;

    /** Saved profile only, without the Git tree: what a new link of this branch would copy, with the first selected paths. */
    public function summary(int $userId, RepositoryRef $ref, string $branch): VisibilitySummary
    {
        $repositories = $this->repositories->forUser($userId, $ref->provider);
        if (!BranchName::valid($branch)) {
            throw Failure::invalid('invalid_branch', 'Invalid branch name.');
        }
        $metadata = $repositories->metadata($ref);
        $pdo = $this->connection->get();
        $repoId = $this->registry->find($userId, $repositories->provider(), $repositories->instance(), $metadata['id']);
        $read = $pdo->prepare('SELECT id, mode, version, selected_count FROM repository_visibility_profiles WHERE user_id = ? AND repository_id = ? AND branch = ?');
        $read->execute([$userId, $repoId ?? 0, $branch]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return new VisibilitySummary($branch, null, 0, 0, []);
        }
        $preview = [];
        if ($row['mode'] === 'selected') {
            $files = $pdo->prepare('SELECT path FROM repository_visibility_files WHERE user_id = ? AND profile_id = ? ORDER BY path LIMIT ' . self::SUMMARY_PREVIEW);
            $files->execute([$userId, (int) $row['id']]);
            foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $preview[] = (string) $path;
            }
        }
        return new VisibilitySummary($branch, (string) $row['mode'], (int) $row['version'], (int) $row['selected_count'], $preview);
    }

    /** Applies bounded select/deselect operations or a mode switch onto the exact saved version and source SHA, all or nothing. */
    public function save(int $userId, RepositoryRef $ref, VisibilityChange $change): VisibilityProfile
    {
        $repositories = $this->repositories->forUser($userId, $ref->provider);
        [$branch, $expected, $mode, $operations] = [$change->branch, $change->expectedVersion, $change->mode, $change->operations];
        // The branch head is checked again without the 30-second cache; a moved branch never receives a selection made on another tree.
        $state = $repositories->branchState($ref, $branch, true);
        if ($state['head'] !== $change->sourceSha) {
            throw Failure::conflict('visibility_tree_changed', 'This branch changed since its files were loaded. Refresh and apply your selection again.');
        }
        $manifest = $repositories->manifest($ref, $state['metadata']['id'], $state['head']);
        if ($operations !== [] && $manifest['truncated']) {
            throw Failure::conflict('visibility_tree_incomplete', 'GitHub returned an incomplete file list for this branch, so a selection cannot be verified. Choose All files in this branch or share a smaller branch.');
        }
        $pdo = $this->connection->get();
        $repoId = Transaction::run($pdo, function () use ($pdo, $repositories, $state, $userId, $branch, $expected, $mode, $operations, $manifest): int {
            $repoId = $this->registry->register($state['metadata'], $userId, $repositories->provider(), $repositories->instance());
            $profile = self::load($pdo, $userId, $repoId, $branch, true);
            if (($profile['version'] ?? 0) !== $expected) {
                throw Failure::conflict('visibility_changed', 'These visibility rules were changed elsewhere. Reload them before saving again.');
            }
            $newMode = $mode ?? ($profile['mode'] ?? 'selected');
            $selected = $profile !== null && $profile['mode'] === 'selected' && $newMode === 'selected' ? $profile['paths'] : [];
            $selected = self::apply($selected, $operations, $manifest['files']);
            $bytes = array_sum(array_map(fn(int|string $path): int => strlen((string) $path), array_keys($selected)));
            if (count($selected) > self::MAX_PATHS || $bytes > self::MAX_TOTAL_BYTES) {
                throw Failure::rejected('visibility_limit', 'A branch can share at most 5,000 selected files (1 MiB of paths). Choose fewer files or All files in this branch.');
            }
            self::write($pdo, $userId, $repoId, $branch, $newMode, $state['head'], $profile, array_keys($selected));
            $this->audit->record(
                'visibility_profile_saved',
                'user',
                $userId,
                $userId,
                ['version' => $expected + 1, 'mode' => $newMode, 'selectedCount' => count($selected)],
                sessionAudience: UserAuth::AUDIENCE,
                repositoryId: $repoId,
            );
            return $repoId;
        });
        return self::dto($branch, $state['head'], $manifest, self::load($pdo, $userId, $repoId, $branch, false));
    }

    /** @param array<string, true> $selected
     *  @param list<array{action: string, path: string}> $operations
     *  @param array<array-key, int|null> $files
     *  @return array<string, true>
     */
    private static function apply(array $selected, array $operations, array $files): array
    {
        foreach ($operations as $operation) {
            $path = $operation['path'];
            if ($operation['action'] === 'deselect') {
                foreach (array_keys($selected) as $file) {
                    if (VisibilityPaths::within((string) $file, $path)) {
                        unset($selected[$file]);
                    }
                }
                continue;
            }
            // A file is selected by its exact path; a folder by all of its current regular files, found on the server's manifest.
            $matched = false;
            if ($path !== '' && array_key_exists($path, $files)) {
                $selected[$path] = true;
                $matched = true;
            } else {
                foreach (array_keys($files) as $file) {
                    if (VisibilityPaths::within((string) $file, $path)) {
                        $selected[(string) $file] = true;
                        $matched = true;
                    }
                }
            }
            if (!$matched) {
                throw Failure::conflict('visibility_path_unavailable', 'A selected file or folder is not in this branch anymore. Refresh and try again.');
            }
        }
        return $selected;
    }

    /** @return array{id: int, mode: string, version: int, sourceSha: string|null, paths: array<string, true>}|null */
    private static function load(PDO $pdo, int $userId, int $repoId, string $branch, bool $lock): ?array
    {
        $read = $pdo->prepare('SELECT id, mode, version, source_sha FROM repository_visibility_profiles WHERE user_id = ? AND repository_id = ? AND branch = ?' . ($lock ? ' FOR UPDATE' : ''));
        $read->execute([$userId, $repoId, $branch]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $files = $pdo->prepare('SELECT path FROM repository_visibility_files WHERE profile_id = ? AND user_id = ?');
        $files->execute([(int) $row['id'], $userId]);
        $paths = [];
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $paths[(string) $path] = true;
        }
        return ['id' => (int) $row['id'], 'mode' => (string) $row['mode'], 'version' => (int) $row['version'],
            'sourceSha' => $row['source_sha'] === null ? null : (string) $row['source_sha'], 'paths' => $paths];
    }

    /** @param array{id: int, mode: string, version: int, sourceSha: string|null, paths: array<string, true>}|null $profile
     *  @param list<int|string> $paths
     */
    private static function write(PDO $pdo, int $userId, int $repoId, string $branch, string $mode, string $head, ?array $profile, array $paths): void
    {
        if ($profile === null) {
            $pdo->prepare('INSERT INTO repository_visibility_profiles (user_id, repository_id, branch, mode, version, source_sha, selected_count, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')->execute([$userId, $repoId, $branch, $mode, $head, count($paths)]);
            $profileId = (int) $pdo->lastInsertId();
        } else {
            $profileId = $profile['id'];
            $pdo->prepare('UPDATE repository_visibility_profiles SET mode = ?, version = version + 1, source_sha = ?, selected_count = ?, updated_at = UTC_TIMESTAMP(6)
                WHERE id = ? AND user_id = ?')->execute([$mode, $head, count($paths), $profileId, $userId]);
            $pdo->prepare('DELETE FROM repository_visibility_files WHERE profile_id = ? AND user_id = ?')->execute([$profileId, $userId]);
        }
        foreach (array_chunk($paths, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $path) {
                array_push($values, $profileId, $userId, (string) $path, VisibilityPaths::hash((string) $path));
            }
            $pdo->prepare('INSERT INTO repository_visibility_files (profile_id, user_id, path, path_hash) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)')))
                ->execute($values);
        }
    }

    /** @param array{files: array<array-key, int|null>, truncated: bool} $manifest
     *  @param array{id: int, mode: string, version: int, sourceSha: string|null, paths: array<string, true>}|null $profile
     */
    private static function dto(string $branch, string $head, array $manifest, ?array $profile): VisibilityProfile
    {
        $files = array_map('strval', array_keys($manifest['files']));
        sort($files, SORT_STRING);
        $selected = array_map('strval', array_keys($profile['paths'] ?? []));
        sort($selected, SORT_STRING);
        $complete = !$manifest['truncated'] && count($files) <= self::MAX_MANIFEST_FILES;
        return new VisibilityProfile(
            $branch,
            $head,
            $profile['mode'] ?? null,
            $profile['version'] ?? 0,
            $profile['sourceSha'] ?? null,
            $profile !== null && $profile['sourceSha'] !== $head,
            $selected,
            $manifest['truncated'] ? [] : array_values(array_filter($selected, fn(string $path): bool => !array_key_exists($path, $manifest['files']))),
            $complete ? $files : null,
            count($files),
            $manifest['truncated'],
            ['paths' => self::MAX_PATHS, 'bytes' => self::MAX_TOTAL_BYTES, 'operations' => VisibilityChange::MAX_OPERATIONS],
        );
    }
}
