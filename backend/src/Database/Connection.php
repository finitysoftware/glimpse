<?php

declare(strict_types=1);

namespace Glimpse\Database;

use Glimpse\Settings;
use PDO;
use RuntimeException;

final class Connection
{
    private ?PDO $pdo;

    /** $pdo: a connection that is already open (a migration run or a test), used instead of opening another one. */
    public function __construct(private readonly Settings $settings, ?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function get(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $db = $this->settings->database;
        if (!str_starts_with($db['dsn'], 'mysql:') || !str_contains($db['dsn'], 'charset=utf8mb4')) {
            throw new RuntimeException('Database is not configured.');
        }
        $this->pdo = new PDO($db['dsn'], $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
        return $this->pdo;
    }

}
