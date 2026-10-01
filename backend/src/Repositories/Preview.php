<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Source\RepositoryClient;
use JsonSerializable;

/**
 * One preview of a repository: a folder, a file, search results, History or one commit, at the commit it was read from.
 * Only the content of its kind is set; the JSON leaves out the others, and the link rules version when there is none.
 *
 * @phpstan-import-type Listing from RepositoryClient
 * @phpstan-import-type FileContent from RepositoryClient
 * @phpstan-import-type SearchPage from RepositoryClient
 * @phpstan-import-type CommitDetail from RepositoryClient
 * @phpstan-import-type CommitHistory from RepositoryClient
 * @phpstan-import-type FilteredHistoryPage from FilteredHistory
 * @phpstan-type ShownRepository array{provider?: string, id: int, fullName: string, private: bool, defaultBranch: string, owner?: string, name?: string, visibility?: string}
 */
final readonly class Preview implements JsonSerializable
{
    /**
     * @param ShownRepository $repository
     * @param FileContent|null $file
     * @param CommitHistory|FilteredHistoryPage|null $history
     * @param CommitDetail|null $detail
     * @param SearchPage|null $search
     * @param Listing['entries']|null $entries
     */
    public function __construct(
        public array $repository,
        public string $commitSha,
        public string $path,
        /** directory, file, history, commit or search. */
        public string $kind,
        public string $branch,
        public bool $allowHistory,
        /** Whether the commit is older than the head of the branch. */
        public bool $historical,
        /** When the platform last confirmed access; '' until the preview is read. */
        public string $checkedAt = '',
        /** 'cache' or the platform that served the content ('upstream' for link visitors). */
        public string $contentSource = '',
        public ?array $file = null,
        public ?array $history = null,
        public ?array $detail = null,
        public ?array $search = null,
        public ?array $entries = null,
        /** Whether the folder listing was cut by the platform. */
        public ?bool $limited = null,
        /** The link rules the preview was read under; only for link visitors. */
        public ?int $policyVersion = null,
    ) {}

    /**
     * The preview with the content of its kind.
     *
     * @param FileContent|null $file
     * @param CommitHistory|FilteredHistoryPage|null $history
     * @param CommitDetail|null $detail
     * @param SearchPage|null $search
     * @param Listing|null $listing A folder's entries and whether the platform cut them.
     */
    public function withContent(?array $file = null, ?array $history = null, ?array $detail = null, ?array $search = null, ?array $listing = null): self
    {
        return new self(
            $this->repository,
            $this->commitSha,
            $this->path,
            $this->kind,
            $this->branch,
            $this->allowHistory,
            $this->historical,
            $this->checkedAt,
            $this->contentSource,
            $file,
            $history,
            $detail,
            $search,
            $listing['entries'] ?? null,
            $listing['limited'] ?? null,
            $this->policyVersion,
        );
    }

    /** A copy with another access check time, content source or link rules version; everything else stays the same. */
    public function with(?string $checkedAt = null, ?string $contentSource = null, ?int $policyVersion = null): self
    {
        return $this->copy($this->repository, $checkedAt ?? $this->checkedAt, $contentSource ?? $this->contentSource, $policyVersion ?? $this->policyVersion);
    }

    /**
     * A copy with other repository fields, e.g. without the platform for a link visitor.
     *
     * @param ShownRepository $repository
     */
    public function withRepository(array $repository): self
    {
        return $this->copy($repository, $this->checkedAt, $this->contentSource, $this->policyVersion);
    }

    /** @param ShownRepository $repository */
    private function copy(array $repository, string $checkedAt, string $contentSource, ?int $policyVersion): self
    {
        return new self(
            $repository,
            $this->commitSha,
            $this->path,
            $this->kind,
            $this->branch,
            $this->allowHistory,
            $this->historical,
            $checkedAt,
            $contentSource,
            $this->file,
            $this->history,
            $this->detail,
            $this->search,
            $this->entries,
            $this->limited,
            $policyVersion,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }
}
