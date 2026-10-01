<?php

declare(strict_types=1);

namespace Glimpse\Connections;

/** A user's saved connection to one platform, as its row holds it; the token stays encrypted. */
final readonly class SavedConnection
{
    /** @param array<string, string|null> $account The platform's own columns, e.g. github_login or token_scopes. */
    public function __construct(
        public int $id,
        public string $encryptedToken,
        public string $keyId,
        /** The credential generation; every saved token gets a new one. */
        public int $version,
        public string $status,
        public ?string $errorCode,
        public ?string $checkedAt,
        /** The GitLab instance the token belongs to; null for GitHub. */
        public ?string $instance,
        public array $account,
    ) {}
}
