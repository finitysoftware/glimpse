<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Source\Transport;
use SensitiveParameter;

final readonly class CurlTransports implements GitHubTransports
{
    public function forToken(#[SensitiveParameter] string $token): Transport
    {
        return new CurlTransport($token);
    }
}
