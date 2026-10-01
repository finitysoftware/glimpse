<?php

declare(strict_types=1);

use Glimpse\Database\Connection;
use Glimpse\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';

// Usage: php bin/maintenance-mode.php on|off|status
// While on, every API route (workspace, visitors and health) answers 503 maintenance except for maintenance_allow_ips;
// static pages stay online.
$command = $argv[1] ?? 'status';
$config = dirname(__DIR__) . '/config';
$flag = $config . '/maintenance.flag';
if (!in_array($command, ['on', 'off', 'status'], true)) {
    fwrite(STDERR, "Usage: php bin/maintenance-mode.php on|off|status\n");
    exit(64);
}
if ($command === 'on' && !is_file($flag) && file_put_contents($flag, gmdate('c') . "\n") === false) {
    fwrite(STDERR, "Cannot create the maintenance flag in the private config directory.\n");
    exit(1);
}
if ($command === 'off' && is_file($flag) && !unlink($flag)) {
    fwrite(STDERR, "Cannot remove the maintenance flag.\n");
    exit(1);
}
clearstatcache();
$environment = getenv('GLIMPSE_MAINTENANCE') === '1';
echo 'Maintenance: ' . (is_file($flag) || $environment ? 'on' : 'off') . ($environment ? " (forced by GLIMPSE_MAINTENANCE)\n" : "\n");
// Requests that started before the flag may still hold GitHub reservations; wait until none remain.
try {
    $pdo = (new Connection(Settings::load($config)))->get();
    $leases = $pdo->query('SELECT COUNT(*) FROM api_leases WHERE expires_at > UTC_TIMESTAMP(6)');
    echo 'Active GitHub reservations: ' . ($leases === false ? 'unknown' : (int) $leases->fetchColumn()) . "\n";
} catch (Throwable) {
    echo "Active GitHub reservations: unknown (database unavailable)\n";
}
