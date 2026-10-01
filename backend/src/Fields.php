<?php

declare(strict_types=1);

namespace Glimpse;

/** Single values of decoded request data (a JSON body or a query string), for the typed inputs and the HTTP layer. */
final class Fields
{
    /**
     * A text value of a body or query string; a missing one is $default.
     *
     * @param array<string, mixed> $values
     */
    public static function text(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;
        if (!is_string($value) || strlen($value) > 2048) {
            throw Failure::invalid('invalid_input', 'Invalid request parameter.');
        }
        return $value;
    }
}
