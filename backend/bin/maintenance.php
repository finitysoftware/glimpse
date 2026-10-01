<?php

declare(strict_types=1);

use Glimpse\Audit\DailyMetrics;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';

// Safe to run from cron at any time: it only removes expired data and recalculates the shared cache budget.
try {
    $connection = new Connection(Settings::load(dirname(__DIR__) . '/config'));
    $pdo = $connection->get();
    $cache = (new PreviewCaches($connection))->purgeExpired();
    $sessions = $pdo->exec('DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP(6) OR revoked_at IS NOT NULL');
    $throttles = $pdo->exec('DELETE FROM auth_throttles WHERE expires_at <= UTC_TIMESTAMP(6)');
    $leases = $pdo->exec('DELETE FROM api_leases WHERE expires_at <= UTC_TIMESTAMP(6)');
    $metrics = (new DailyMetrics($connection))->purgeExpired();
    echo 'Removed ' . $cache . ' expired cache entries, ' . (int) $sessions . ' sessions, ' . (int) $throttles . ' throttle windows, '
        . (int) $leases . ' reservations and ' . $metrics . " old daily counters.\n";
} catch (Throwable) {
    fwrite(STDERR, "Maintenance failed. Check private database configuration and applied migrations.\n");
    exit(1);
}
