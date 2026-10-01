<?php

declare(strict_types=1);

namespace Glimpse\Database;

/** Database times as the API sends them. Columns hold UTC (the connection's time zone is +00:00) as 'Y-m-d H:i:s[.u]'. */
final class Dates
{
    /** A DATETIME column as ISO 8601 in UTC, e.g. 2026-09-25T10:00:00.000000Z. */
    public static function iso(string $value): string
    {
        return str_replace(' ', 'T', $value) . 'Z';
    }

    /** The same for a column that may be NULL. */
    public static function isoOrNull(mixed $value): ?string
    {
        return is_string($value) ? self::iso($value) : null;
    }
}
