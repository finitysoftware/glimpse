<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Cache\CachedTransport;
use Glimpse\Cache\PreviewCache;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\Source\BranchName;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\SourceAccess;
use LogicException;

/** @phpstan-import-type Metadata from RepositoryClient */
final readonly class RepositoryService
{
    /** Bound to one owner's connection to one platform; every upstream call and cache entry belongs to that owner and platform. */
    public function __construct(
        private Connection $connection,
        private SourceAccess $access,
        private Platform $platform,
        private PreviewCaches $caches,
        private int $cacheBudget = PreviewCache::GLOBAL_MAX_BYTES,
    ) {}

    public function ownerId(): int
    {
        return $this->access->userId;
    }

    public function provider(): Provider
    {
        return $this->access->provider;
    }

    public function instance(): string
    {
        return $this->access->instance;
    }

    /** Cache scope of one repository of this service's platform. */
    public function scope(RepositoryRef $ref): string
    {
        return PreviewCache::scope($ref->provider, $ref->key());
    }

    /** One page of the owner's repositories on this platform, each tagged with the platform. */
    public function catalogue(int $page): Catalogue
    {
        $transport = $this->transport(hash('sha256', 'catalogue', true), true);
        $provider = $this->access->provider;
        $result = $this->platform->catalogue($transport, $page);
        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = new CatalogueRepository(
                $provider->value,
                $item['id'],
                $item['fullName'],
                $item['owner'],
                $item['name'],
                $item['private'],
                $item['visibility'] ?? ($item['private'] ? 'private' : 'public'),
                $item['defaultBranch'],
                $item['description'],
            );
        }
        return new Catalogue($items, $result['nextPage']);
    }

    /** @return Metadata */
    public function metadata(RepositoryRef $ref): array
    {
        return $this->client($ref, $this->transport($this->scope($ref), true))->metadata();
    }

    /** @param list<string> $branches */
    public function validateBranches(RepositoryRef $ref, array $branches, int $externalId): void
    {
        $transport = $this->transport($this->scope($ref), true);
        $transport->bindRepository($externalId);
        $repository = $this->client($ref, $transport);
        // Only the selected names are retained. The shared request deadline bounds large selections.
        foreach ($branches as $branch) {
            $repository->branchHead($branch);
        }
    }

    /** Current head of one branch, checked through the owner's token; $refresh bypasses the 30-second head cache.
     * @return array{metadata: Metadata, head: string}
     */
    public function branchState(RepositoryRef $ref, string $branch, bool $refresh): array
    {
        if (!BranchName::valid($branch)) {
            throw Failure::invalid('invalid_branch', 'Invalid branch name.');
        }
        $transport = $this->transport($this->scope($ref), $refresh);
        $repository = $this->client($ref, $transport);
        $metadata = $repository->metadata();
        $transport->bindRepository($metadata['id']);
        return ['metadata' => $metadata, 'head' => $repository->branchHead($branch)];
    }

    /** Regular files of one commit, from cached tree metadata.
     * @return array{files: array<array-key, int|null>, truncated: bool}
     */
    public function manifest(RepositoryRef $ref, int $externalId, string $sha): array
    {
        $transport = $this->transport($this->scope($ref), false);
        $transport->bindRepository($externalId);
        return $this->client($ref, $transport)->files($sha);
    }

    public function browse(RepositoryRef $ref, BrowseQuery $query, ?int $expectedId = null, ?BrowseAccess $access = null): Preview|BranchChoices
    {
        $provider = $this->access->provider;
        $scope = $this->scope($ref);
        $transport = $this->transport($scope, $query->refresh);
        try {
            $repository = $this->client($ref, $transport);
            // A positive metadata check lasts at most 30 seconds; Refresh always revalidates it.
            $metadata = $repository->metadata();
            $transport->bindRepository($metadata['id']);
            if ($expectedId !== null && $metadata['id'] !== $expectedId) {
                $this->caches->invalidate($this->access->userId, $provider, $scope);
                throw Failure::forbidden('repository_unavailable', 'This repository is no longer available through this link.');
            }
            $preview = (new RepositoryBrowser($repository, $provider, static fn(): int => $transport->fetched))->read($metadata, $query, $access);
            return $preview->with(checkedAt: $transport->checkedAt, contentSource: $transport->contentSource);
        } catch (Failure $error) {
            if ($error->errorCode === $provider->code('invalid_response')) {
                $this->caches->invalidate($this->access->userId, $provider, $scope);
            }
            throw $error;
        }
    }

    /** One request's reads of this owner's platform, cached under $scope; $refresh checks the platform again instead of the cache. */
    private function transport(string $scope, bool $refresh): CachedTransport
    {
        return new CachedTransport($this->connection, $this->access, $this->caches, $scope, $refresh, $this->cacheBudget);
    }

    private function client(RepositoryRef $ref, CachedTransport $transport): RepositoryClient
    {
        if ($ref->provider !== $this->access->provider) {
            throw new LogicException('The repository and the connection belong to different platforms.');
        }
        return $this->platform->repository($ref, $transport);
    }
}
