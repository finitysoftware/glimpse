<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Connections\ConnectionStatus;

final readonly class GitHubConnectionStatus implements ConnectionStatus
{
    public function __construct(
        public bool $configured,
        public string $status,
        public ?string $maskedToken,
        public int $credentialVersion,
        public ?string $githubLogin,
        public ?string $lastCheckedAt,
        public ?string $errorCode,
    ) {}
}
