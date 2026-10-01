<?php

declare(strict_types=1);

use Glimpse\Database\Connection;
use Glimpse\Database\MigrationException;
use Glimpse\Database\Migrator;
use Glimpse\Settings;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';

$usage = "Usage: php bin/migrate.php [--to=<number>] | --status\n";
$target = null;
$status = false;
$arguments = array_slice($argv ?? [], 1);
for ($i = 0; $i < count($arguments); $i++) {
    $argument = $arguments[$i];
    if ($argument === '--status') {
        $status = true;
    } elseif (str_starts_with($argument, '--to=')) {
        $target = substr($argument, 5);
    } elseif ($argument === '--to' && isset($arguments[$i + 1])) {
        $target = $arguments[++$i];
    } else {
        fwrite(STDERR, $usage);
        exit(64);
    }
}
if ($status && $target !== null) {
    fwrite(STDERR, $usage);
    exit(64);
}

try {
    $connection = new Connection(Settings::load(dirname(__DIR__) . '/config'));
    $migrator = new Migrator($connection, dirname(__DIR__) . '/migrations');
    if ($status) {
        foreach ($migrator->status() as $row) {
            echo str_pad($row['state'], 12) . $row['version'] . "\n";
        }
        exit(0);
    }
    $result = $migrator->run($target);
    echo $result->applied === [] ? "No migrations applied.\n" : 'Applied: ' . implode(', ', $result->applied) . "\n";
    if ($result->blocked()) {
        // Exit 2: nothing failed, but the schema is intentionally incomplete until the named step is done.
        echo 'Stopped before ' . $result->blockedBy . ': ' . $result->reason . "\n";
        exit(2);
    }
    echo $target === null ? "Schema is up to date.\n" : "Reached the requested migration.\n";
} catch (MigrationException $error) {
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Migration failed. Check private database configuration, privileges and schema_migrations for an interrupted or changed migration.\n");
    exit(1);
}
