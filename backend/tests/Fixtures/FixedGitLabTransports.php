<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\GitLab\GitLabTransports;
use Glimpse\GitLab\GitLabUrl;
use Glimpse\Source\Transport;
use SensitiveParameter;

/** Routes every GitLab connection to one deterministic transport and records the instance and token each request would use. */
final class FixedGitLabTransports implements GitLabTransports
{
    /** @var list<array{url: string, token: string}> */
    public array $connections = [];

    public function __construct(private readonly Transport $transport) {}

    public function forConnection(GitLabUrl $url, #[SensitiveParameter] string $token): Transport
    {
        $this->connections[] = ['url' => $url->base, 'token' => $token];
        return $this->transport;
    }
}
