<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Cache\CachedTransport;
use Glimpse\GitLab\GitLabClient;
use Glimpse\GitLab\GitLabConnectionService;
use Glimpse\GitLab\GitLabRepository;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\SourceAccess;

/** GitLab: projects by their stable ID on the instance the owner connected. */
final readonly class GitLabPlatform implements Platform
{
    public function __construct(private GitLabConnectionService $connections) {}

    public function provider(): Provider
    {
        return Provider::GitLab;
    }

    public function access(int $userId): SourceAccess
    {
        return $this->connections->access($userId);
    }

    public function instance(int $userId): ?string
    {
        return $this->connections->instance($userId);
    }

    public function catalogue(CachedTransport $transport, int $page): array
    {
        return (new GitLabClient($transport))->projects($page);
    }

    public function repository(RepositoryRef $ref, CachedTransport $transport): RepositoryClient
    {
        // The cached transport also keeps GitLab's derived results, such as the commit history of one path.
        return new GitLabRepository(new GitLabClient($transport, $transport), $ref->projectId);
    }
}
