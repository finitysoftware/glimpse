<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use SensitiveParameter;

/** CSRF tokens derived from an HttpOnly session token and bound to its audience, so a workspace token never validates a visitor request. */
final class Csrf
{
    public static function token(string $audience, #[SensitiveParameter] string $sessionToken): string
    {
        return hash('sha256', 'glimpse:csrf:' . $audience . ':' . $sessionToken);
    }

    public static function valid(string $audience, #[SensitiveParameter] string $sessionToken, string $submitted): bool
    {
        return $sessionToken !== '' && hash_equals(self::token($audience, $sessionToken), $submitted);
    }
}
