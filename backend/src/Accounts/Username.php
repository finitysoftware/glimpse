<?php

declare(strict_types=1);

namespace Glimpse\Accounts;

use Glimpse\Failure;

final class Username
{
    /** The same normalization applies when an account is created and when its owner signs in. */
    public static function normalize(string $input): string
    {
        $username = strtolower(trim($input, " \t\n\r\0\x0B"));
        if (preg_match('/^[a-z0-9_.-]{3,32}$/D', $username) !== 1) {
            throw Failure::invalid('invalid_username', 'Use 3-32 characters: ASCII letters, digits, "_", "-" or ".".');
        }
        return $username;
    }
}
