<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Source\ManifestSearch;
use Glimpse\Source\RepositoryClient;

/** One GitHub repository addressed by owner and name. */
final readonly class GitHubRepository implements RepositoryClient
{
    public function __construct(private GitHubClient $github, private string $owner, private string $name) {}

    public function metadata(): array
    {
        return $this->github->metadata($this->owner, $this->name);
    }

    public function branches(int $page): array
    {
        return $this->github->branches($this->owner, $this->name, $page);
    }

    public function branchHead(string $branch): string
    {
        return $this->github->branchHead($this->owner, $this->name, $branch);
    }

    public function resolveCommit(string $ref): string
    {
        return $this->github->resolveCommit($this->owner, $this->name, $ref);
    }

    public function isAncestor(string $sha, string $head): bool
    {
        return $this->github->isAncestor($this->owner, $this->name, $sha, $head);
    }

    public function directory(string $path, string $sha): array
    {
        return $this->github->directory($this->owner, $this->name, $path, $sha);
    }

    public function files(string $sha): array
    {
        return $this->github->files($this->owner, $this->name, $sha);
    }

    public function search(string $sha, string $query, int $page, ?array $visible): array
    {
        return ManifestSearch::search($sha, $query, $page, $visible, fn(): array => $this->files($sha));
    }

    public function history(string $sha, string $path, int $page): array
    {
        return $this->github->history($this->owner, $this->name, $sha, $path, $page);
    }

    public function commitPage(string $sha, string $path, int $page): array
    {
        return $this->github->commitPage($this->owner, $this->name, $sha, $path, $page);
    }

    public function commit(string $sha, int $page): array
    {
        return $this->github->commit($this->owner, $this->name, $sha, $page);
    }

    public function commitFiles(string $sha, int $page): array
    {
        return $this->github->commitFiles($this->owner, $this->name, $sha, $page);
    }

    public function file(string $path, string $sha): array
    {
        return $this->github->file($this->owner, $this->name, $path, $sha);
    }
}
