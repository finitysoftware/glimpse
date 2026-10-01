<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Source\Transport;
use SensitiveParameter;

/** Creates a transport for one instance and token; there is no server-wide GitLab token. */
interface GitLabTransports
{
    public function forConnection(GitLabUrl $url, #[SensitiveParameter] string $token): Transport;
}
