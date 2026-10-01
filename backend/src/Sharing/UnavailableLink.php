<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;

/** The one answer for a link that cannot be used: invalid, expired, revoked, disabled or another owner's all look the same. */
final class UnavailableLink
{
    public static function failure(): Failure
    {
        return Failure::notFound('share_unavailable', 'This link is invalid, expired, revoked, or unavailable. Open the original link to try again.');
    }
}
