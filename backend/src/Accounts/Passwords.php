<?php

declare(strict_types=1);

namespace Glimpse\Accounts;

use Glimpse\Failure;
use SensitiveParameter;

final class Passwords
{
    public const MIN_CHARACTERS = 8;
    public const MAX_CHARACTERS = 128;
    public const MAX_BYTES = 1024;
    public const BCRYPT_MAX_BYTES = 72;
    // Verified for unknown usernames so every sign-in performs one hash check with the current parameters.
    // Regenerate both when the hashing algorithm or cost changes; a unit test compares their parameters.
    public const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$TzQvOUlLLkJuWUZ0Y1U2QQ$cByc4ppCRLL9sDavthKflnJA2ijYNQM1BQPjUUonHCo';
    public const DUMMY_BCRYPT = '$2y$12$qGtjBOqSme56rtZm4EibxuyD3onIQ3YiJTm7mCzepo7lmrqno26Lm';

    /** Policy for new and reset passwords. The value is never trimmed or otherwise changed. */
    public static function validateNew(#[SensitiveParameter] string $password): void
    {
        $characters = mb_check_encoding($password, 'UTF-8') ? mb_strlen($password, 'UTF-8') : 0;
        $maxBytes = self::argon2id() ? self::MAX_BYTES : self::BCRYPT_MAX_BYTES;
        if ($characters < self::MIN_CHARACTERS || $characters > self::MAX_CHARACTERS || strlen($password) > $maxBytes || str_contains($password, "\0")) {
            throw Failure::invalid('invalid_password', self::argon2id()
                ? 'Use 8-128 characters (at most 1024 UTF-8 bytes).'
                : 'Use 8-128 characters (at most 72 UTF-8 bytes on this server).');
        }
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        self::validateNew($password);
        // Bcrypt is only an explicit fallback; validateNew() rejects input it would silently truncate.
        $hash = password_hash($password, self::argon2id() ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
        if (!password_verify($password, $hash)) {
            throw new \RuntimeException('The generated password hash could not be verified.');
        }
        return $hash;
    }

    /** Always performs exactly one verification; a NULL hash (unknown account) is checked against the dummy hash. */
    public static function verify(#[SensitiveParameter] string $password, #[SensitiveParameter] ?string $hash): bool
    {
        $valid = password_verify($password, $hash ?? (self::argon2id() ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT));
        return $hash !== null && $valid;
    }

    /** New hash for a verified password whose stored hash uses older parameters; NULL when it cannot be rehashed without truncation.
     * The new-password policy is not applied: a password that was valid when it was set stays valid until its owner changes it.
     */
    public static function rehash(#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): ?string
    {
        $algorithm = self::argon2id() ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (!password_needs_rehash($hash, $algorithm) || ($algorithm === PASSWORD_BCRYPT && strlen($password) > self::BCRYPT_MAX_BYTES)) {
            return null;
        }
        return password_hash($password, $algorithm);
    }

    public static function argon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }
}
