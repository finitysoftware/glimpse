<?php

declare(strict_types=1);

namespace Glimpse\Source;

use Glimpse\Failure;
use PDO;

/** Connection state changes observed while serving requests, for either platform. */
final class ConnectionState
{
    public static function notConnected(Provider $provider): Failure
    {
        return Failure::conflict($provider->code('not_connected'), $provider === Provider::GitHub
            ? 'Connect GitHub to see your repositories.' : 'Connect GitLab to see your GitLab repositories.');
    }

    /** A 401 for the current token marks only that credential version as rejected. */
    public static function markRejected(PDO $pdo, Provider $provider, int $userId, int $credentialVersion): void
    {
        $pdo->prepare("UPDATE " . $provider->connectionTable() . " SET last_check_status = 'credentials_rejected', last_error_code = ?,
            last_checked_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND credential_version = ?")
            ->execute([$provider->code('credentials_rejected'), $userId, $credentialVersion]);
    }
}
