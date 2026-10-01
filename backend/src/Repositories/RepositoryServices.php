<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Cache\CacheStatus;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Source\Provider;
use LogicException;

/** The repository service of each owner and platform. Every upstream call uses the owner's own token; there is no server-wide token. */
final readonly class RepositoryServices
{
    /** @var array<string, Platform> */
    private array $platforms;

    /** @param list<Platform> $platforms */
    public function __construct(private Connection $connection, array $platforms, private PreviewCaches $caches, private int $cacheBudget)
    {
        $this->platforms = array_combine(array_map(static fn(Platform $platform): string => $platform->provider()->value, $platforms), $platforms);
    }

    /** Fails with the platform's not-connected error when the owner has no usable token there. */
    public function forUser(int $userId, Provider $provider = Provider::GitHub): RepositoryService
    {
        $platform = $this->platform($provider);
        return new RepositoryService($this->connection, $platform->access($userId), $platform, $this->caches, $this->cacheBudget);
    }

    /** The owner's preview cache and each platform's API quota. */
    public function cacheStatus(int $userId): CacheStatus
    {
        return $this->caches->status($userId);
    }

    /** The instance an owner's repositories of one platform are registered on, known without decrypting any token. */
    public function instance(int $userId, Provider $provider): ?string
    {
        return $this->platform($provider)->instance($userId);
    }

    private function platform(Provider $provider): Platform
    {
        return $this->platforms[$provider->value] ?? throw new LogicException('No platform is registered for ' . $provider->value . '.');
    }
}
