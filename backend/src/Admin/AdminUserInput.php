<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Failure;

/** Validation of admin panel account fields; every rejection is a 400 with a message the form can show. */
final class AdminUserInput
{
    public static function name(mixed $value, string $label): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 64 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw Failure::invalid('invalid_input', 'Enter a ' . $label . ' of up to 64 characters.');
        }
        return $name;
    }

    /** Optional contact address: stored lowercase, ASCII only; an empty value clears it. */
    public static function email(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $email = is_string($value) ? strtolower(trim($value)) : '';
        if (strlen($email) > 254 || preg_match('/^[\x21-\x7e]+@[\x21-\x7e]+\.[\x21-\x7e]+$/D', $email) !== 1 || substr_count($email, '@') !== 1) {
            throw Failure::invalid('invalid_email', 'Enter a valid email address.');
        }
        return $email;
    }

    /** "admin" means member and platform administrator; "user" means member only. */
    public static function admin(mixed $value): bool
    {
        if (!in_array($value, ['user', 'admin'], true)) {
            throw Failure::invalid('invalid_input', 'Choose the role User or Admin.');
        }
        return $value === 'admin';
    }

    public static function flag(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw Failure::invalid('invalid_input', 'Invalid value for ' . $field . '.');
        }
        return $value;
    }

    public static function version(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw Failure::invalid('invalid_input', 'The account version is missing. Reload and try again.');
        }
        return $value;
    }
}
