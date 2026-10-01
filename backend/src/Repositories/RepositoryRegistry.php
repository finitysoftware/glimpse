<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\Source\Provider;
use PDO;

/** Local repository rows belong to one user and are identified by the platform, its instance and the platform's stable repository ID. */
final readonly class RepositoryRegistry
{
    public function __construct(private Connection $connection) {}

    /** Registers or refreshes the owner's row inside the caller's transaction. GitHub metadata names the repository by `owner/name`;
     * other platforms pass `owner` (the namespace) and `name` explicitly.
     * @param array{id: int, fullName: string, defaultBranch: string, owner?: string, name?: string} $metadata
     */
    public function register(array $metadata, int $userId, Provider $provider, string $instance): int
    {
        $pdo = $this->connection->get();
        if (isset($metadata['owner'], $metadata['name'])) {
            [$owner, $name] = [$metadata['owner'], $metadata['name']];
        } else {
            [$owner, $name] = explode('/', $metadata['fullName'], 2) + ['', ''];
        }
        $read = $pdo->prepare('SELECT id, external_id FROM repositories WHERE user_id = ? AND provider = ? AND instance_url = ?
            AND (external_id = ? OR (owner = ? AND name = ?)) FOR UPDATE');
        $read->execute([$userId, $provider->value, $instance, $metadata['id'], $owner, $name]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            // The same owner/name with a different stable ID never takes over existing links or visibility rules.
            if ((int) $row['external_id'] !== $metadata['id']) {
                throw Failure::conflict('repository_identity_changed', 'This name belongs to a different repository than the existing links.');
            }
            $pdo->prepare('UPDATE repositories SET owner = ?, name = ?, default_branch = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ?')
                ->execute([$owner, $name, $metadata['defaultBranch'], $row['id'], $userId]);
            return (int) $row['id'];
        }
        $pdo->prepare('INSERT INTO repositories (user_id, provider, instance_url, external_id, owner, name, default_branch, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
            ->execute([$userId, $provider->value, $instance, $metadata['id'], $owner, $name, $metadata['defaultBranch']]);
        return (int) $pdo->lastInsertId();
    }

    public function find(int $userId, Provider $provider, string $instance, int $externalId): ?int
    {
        $pdo = $this->connection->get();
        $read = $pdo->prepare('SELECT id FROM repositories WHERE user_id = ? AND provider = ? AND instance_url = ? AND external_id = ?');
        $read->execute([$userId, $provider->value, $instance, $externalId]);
        $id = $read->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
