<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use PDO;
use PHPUnit\Framework\Assert;

/** A throwaway database next to glimpse_test, recreated for each migration test so upgrades start from real old schemas. */
final class ScratchDatabase
{
    public const NAME = 'glimpse_test_migrations';

    /** Recreates the scratch database.
     * @return array{dsn: string, username: string, password: string}
     */
    public static function reset(): array
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            Assert::markTestSkipped('GLIMPSE_TEST_DB_DSN is required for a dedicated MySQL/MariaDB test database.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || preg_match('/dbname=glimpse_test(?:;|$)/', $dsn) !== 1) {
            Assert::fail('Destructive tests require dbname=glimpse_test and GLIMPSE_TEST_DB_RESET=1.');
        }
        $username = getenv('GLIMPSE_TEST_DB_USER') ?: 'root';
        $password = getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '';
        $server = new PDO((string) preg_replace('/dbname=glimpse_test(;|$)/', '', $dsn), $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec('DROP DATABASE IF EXISTS ' . self::NAME);
        $server->exec('CREATE DATABASE ' . self::NAME . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        return ['dsn' => (string) preg_replace('/dbname=glimpse_test(;|$)/', 'dbname=' . self::NAME . '$1', $dsn),
            'username' => $username, 'password' => $password];
    }
}
