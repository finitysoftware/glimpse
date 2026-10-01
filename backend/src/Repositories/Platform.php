<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Cache\CachedTransport;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\SourceAccess;

/**
 * One source platform as the repository area uses it; adding a platform means adding one of these.
 *
 * @phpstan-import-type CatalogueItem from RepositoryClient
 */
interface Platform
{
    public function provider(): Provider;

    /** The owner's access with their saved token; fails with the platform's not-connected error without one. */
    public function access(int $userId): SourceAccess;

    /** The instance the owner's repositories are registered on, known without decrypting a token; NULL without a connection. */
    public function instance(int $userId): ?string;

    /**
     * One page of the repositories the owner's token can read.
     *
     * @return array{items: list<CatalogueItem>, nextPage: int|null}
     */
    public function catalogue(CachedTransport $transport, int $page): array;

    public function repository(RepositoryRef $ref, CachedTransport $transport): RepositoryClient;
}
