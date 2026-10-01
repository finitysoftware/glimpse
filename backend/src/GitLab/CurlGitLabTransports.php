<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Source\Transport;
use SensitiveParameter;

final readonly class CurlGitLabTransports implements GitLabTransports
{
    public function __construct(private GitLabNetworkPolicy $policy) {}

    public function forConnection(GitLabUrl $url, #[SensitiveParameter] string $token): Transport
    {
        return new GitLabCurlTransport($url, $token, $this->policy);
    }
}
