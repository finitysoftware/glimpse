<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Source\Transport;
use SensitiveParameter;

/** Creates a transport for one token; there is no server-wide token. */
interface GitHubTransports
{
    public function forToken(#[SensitiveParameter] string $token): Transport;
}
