<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Source\ManifestSearch;
use Glimpse\Source\RepositoryClient;

/** One GitLab project addressed by its stable numeric ID. */
final readonly class GitLabRepository implements RepositoryClient
{
    public function __construct(private GitLabClient $gitlab, private int $project) {}

    public function metadata(): array
    {
        return $this->gitlab->metadata($this->project);
    }

    public function branches(int $page): array
    {
        return $this->gitlab->branches($this->project, $page);
    }

    public function branchHead(string $branch): string
    {
        return $this->gitlab->branchHead($this->project, $branch);
    }

    public function resolveCommit(string $ref): string
    {
        return $this->gitlab->resolveCommit($this->project, $ref);
    }

    public function isAncestor(string $sha, string $head): bool
    {
        return $this->gitlab->isAncestor($this->project, $sha, $head);
    }

    public function directory(string $path, string $sha): array
    {
        return $this->gitlab->directory($this->project, $path, $sha);
    }

    public function files(string $sha): array
    {
        return $this->gitlab->files($this->project, $sha);
    }

    public function search(string $sha, string $query, int $page, ?array $visible): array
    {
        return ManifestSearch::search($sha, $query, $page, $visible, fn(): array => $this->files($sha));
    }

    public function history(string $sha, string $path, int $page): array
    {
        return $this->gitlab->history($this->project, $sha, $path, $page);
    }

    public function commitPage(string $sha, string $path, int $page): array
    {
        return $this->gitlab->commitPage($this->project, $sha, $path, $page);
    }

    public function commit(string $sha, int $page): array
    {
        return $this->gitlab->commit($this->project, $sha, $page);
    }

    public function commitFiles(string $sha, int $page): array
    {
        return $this->gitlab->commitFiles($this->project, $sha, $page);
    }

    public function file(string $path, string $sha): array
    {
        return $this->gitlab->file($this->project, $path, $sha);
    }
}
