<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

/** Whether a link still opens: revoked wins over expired. */
enum LinkStatus: string
{
    /** SQL on share_links `l`: a link that still opens, neither revoked nor expired. Serving, showing and counting links all use it. */
    public const ACTIVE_SQL = 'l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > UTC_TIMESTAMP(6))';
    /** SQL on share_links `l`: an expired link that was not revoked. */
    public const EXPIRED_SQL = 'l.revoked_at IS NULL AND l.expires_at <= UTC_TIMESTAMP(6)';
    /** SQL on share_links `l`: the status of a link as one of the case values. */
    public const STATUS_SQL = "CASE WHEN l.revoked_at IS NOT NULL THEN 'revoked' WHEN l.expires_at <= UTC_TIMESTAMP(6) THEN 'expired' ELSE 'active' END";

    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
