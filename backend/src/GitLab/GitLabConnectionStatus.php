<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Connections\ConnectionStatus;

final readonly class GitLabConnectionStatus implements ConnectionStatus
{
    /** @param list<string>|null $scopes */
    public function __construct(
        public bool $configured,
        public string $status,
        /** The instance of the saved token. */
        public ?string $baseUrl,
        /** The address suggested for a new connection. */
        public string $defaultUrl,
        public ?string $maskedToken,
        public int $credentialVersion,
        public ?string $gitlabUsername,
        public ?string $tokenExpiresAt,
        public ?array $scopes,
        public ?string $lastCheckedAt,
        public ?string $errorCode,
    ) {}
}
