<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use Glimpse\Database\Connection;
use Glimpse\Failure;
use SensitiveParameter;

/** Fixed 15-minute attempt windows in SQL. Buckets are digests of a namespace and key; secrets are never used as keys. */
final readonly class Throttle
{
    public const WINDOW_SECONDS = 900;

    public function __construct(private Connection $connection) {}

    /** Records one attempt and returns the number of attempts in the current window. */
    public function hit(string $namespace, #[SensitiveParameter] string $key): int
    {
        $pdo = $this->connection->get();
        $digest = self::bucket($namespace, $key);
        $pdo->prepare('INSERT INTO auth_throttles (bucket_digest, attempts, expires_at)
            VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . self::WINDOW_SECONDS . ' SECOND))
            ON DUPLICATE KEY UPDATE
            attempts = IF(expires_at <= UTC_TIMESTAMP(6), 1, LEAST(attempts + 1, 1000000)),
            expires_at = IF(expires_at <= UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ' . self::WINDOW_SECONDS . ' SECOND), expires_at)')
            ->execute([$digest]);
        $read = $pdo->prepare('SELECT attempts FROM auth_throttles WHERE bucket_digest = ?');
        $read->execute([$digest]);
        return (int) $read->fetchColumn();
    }

    /** Throws once the window allows no more attempts. */
    public function limit(string $namespace, #[SensitiveParameter] string $key, int $limit): void
    {
        if ($this->hit($namespace, $key) > $limit) {
            throw self::limited();
        }
    }

    public function clear(string $namespace, #[SensitiveParameter] string $key): void
    {
        $this->connection->get()->prepare('DELETE FROM auth_throttles WHERE bucket_digest = ?')->execute([self::bucket($namespace, $key)]);
    }

    public static function limited(): Failure
    {
        return Failure::rateLimited('rate_limited', 'Too many access attempts. Try again later.', self::WINDOW_SECONDS);
    }

    private static function bucket(string $namespace, string $key): string
    {
        return hash('sha256', 'glimpse.throttle.v2' . "\0" . $namespace . "\0" . $key, true);
    }
}
