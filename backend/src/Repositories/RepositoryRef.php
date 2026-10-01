<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Failure;
use Glimpse\Source\Provider;

/** How a request names a repository: owner and name on GitHub, the stable project ID on GitLab. */
final readonly class RepositoryRef
{
    private function __construct(public Provider $provider, public string $owner, public string $name, public int $projectId) {}

    public static function github(string $owner, string $name): self
    {
        return new self(Provider::GitHub, $owner, $name, 0);
    }

    public static function gitlab(int $projectId): self
    {
        if ($projectId < 1) {
            throw Failure::invalid('invalid_repository', 'Invalid repository.');
        }
        return new self(Provider::GitLab, '', '', $projectId);
    }

    /** The reference of a registered repository row. */
    public static function stored(Provider $provider, string $owner, string $name, int $externalId): self
    {
        return $provider === Provider::GitHub ? self::github($owner, $name) : self::gitlab($externalId);
    }

    /** @param array<array-key, mixed> $row A repositories row with provider, owner, name and external_id. */
    public static function fromRow(array $row): self
    {
        return self::stored(Provider::from((string) $row['provider']), (string) $row['owner'], (string) $row['name'], (int) $row['external_id']);
    }

    /** Stable text within a platform, for cache scopes: `owner/name` on GitHub, the project ID on GitLab. */
    public function key(): string
    {
        return $this->provider === Provider::GitHub ? $this->owner . '/' . $this->name : (string) $this->projectId;
    }

    /** SQL condition (with its parameters) for this repository's local row of one owner on one instance.
     * @return array{string, list<int|string>}
     */
    public function where(int $userId, string $instance, string $alias = ''): array
    {
        $column = static fn(string $name): string => ($alias === '' ? '' : $alias . '.') . $name;
        $base = $column('user_id') . ' = ? AND ' . $column('provider') . ' = ? AND ' . $column('instance_url') . ' = ?';
        return $this->provider === Provider::GitHub
            ? [$base . ' AND ' . $column('owner') . ' = ? AND ' . $column('name') . ' = ?', [$userId, $this->provider->value, $instance, $this->owner, $this->name]]
            : [$base . ' AND ' . $column('external_id') . ' = ?', [$userId, $this->provider->value, $instance, $this->projectId]];
    }
}
