<?php

declare(strict_types=1);

namespace Glimpse\Connections;

/** How Connections shows a platform connection: its state and the masked token, never the token itself. */
interface ConnectionStatus
{
    public bool $configured { get; }

    /** not_configured, not_verified, connected, credentials_rejected, access_restricted or temporarily_unavailable. */
    public string $status { get; }

    /** The first characters of the saved token and asterisks; NULL without one. */
    public ?string $maskedToken { get; }

    /** Changes with every saved token; a save names the version it replaces. */
    public int $credentialVersion { get; }
}
