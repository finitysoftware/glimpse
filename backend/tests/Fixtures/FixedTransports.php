<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\GitHub\GitHubTransports;
use Glimpse\Source\Transport;
use SensitiveParameter;

/** Routes every token to one deterministic transport and records which tokens were used. */
final class FixedTransports implements GitHubTransports
{
    /** @var list<string> */
    public array $tokens = [];

    public function __construct(private readonly Transport $transport) {}

    public function forToken(#[SensitiveParameter] string $token): Transport
    {
        $this->tokens[] = $token;
        return $this->transport;
    }
}
