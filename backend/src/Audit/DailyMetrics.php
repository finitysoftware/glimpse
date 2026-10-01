<?php

declare(strict_types=1);

namespace Glimpse\Audit;

use Glimpse\Database\Connection;
use InvalidArgumentException;

/** Per-day counters for the admin dashboard charts. They survive session cleanup and hold no names, paths or secrets. */
final readonly class DailyMetrics
{
    public function __construct(private Connection $connection) {}

    public const METRICS = ['workspace_login', 'admin_login', 'link_opened', 'link_created'];
    /** Counters older than this are removed by bin/maintenance.php. */
    public const RETENTION_DAYS = 400;

    /** Counts one event for $userId (the signed-in account, or the owner of an opened or created link) on the current UTC day. */
    public function increment(string $metric, int $userId): void
    {
        $pdo = $this->connection->get();
        if (!in_array($metric, self::METRICS, true) || $userId < 1) {
            throw new InvalidArgumentException('Invalid metric.');
        }
        $pdo->prepare('INSERT INTO daily_metrics (day, metric, user_id, count) VALUES (UTC_DATE(), ?, ?, 1)
            ON DUPLICATE KEY UPDATE count = count + 1')->execute([$metric, $userId]);
    }

    public function purgeExpired(): int
    {
        $pdo = $this->connection->get();
        $delete = $pdo->prepare('DELETE FROM daily_metrics WHERE day < DATE_SUB(UTC_DATE(), INTERVAL ? DAY)');
        $delete->execute([self::RETENTION_DAYS]);
        return $delete->rowCount();
    }
}
