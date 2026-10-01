<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Cache\CachedTransport;
use Glimpse\GitHub\GitHubClient;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\GitHub\GitHubRepository;
use Glimpse\Source\Provider;
use Glimpse\Source\RepositoryClient;
use Glimpse\Source\SourceAccess;

/** GitHub: repositories by owner and name on api.github.com. */
final readonly class GitHubPlatform implements Platform
{
    public function __construct(private GitHubConnectionService $connections) {}

    public function provider(): Provider
    {
        return Provider::GitHub;
    }

    public function access(int $userId): SourceAccess
    {
        return $this->connections->access($userId);
    }

    public function instance(int $userId): string
    {
        return Provider::GITHUB_INSTANCE;
    }

    public function catalogue(CachedTransport $transport, int $page): array
    {
        return (new GitHubClient($transport))->repositories($page);
    }

    public function repository(RepositoryRef $ref, CachedTransport $transport): RepositoryClient
    {
        return new GitHubRepository(new GitHubClient($transport), $ref->owner, $ref->name);
    }
}
