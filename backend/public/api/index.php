<?php

declare(strict_types=1);

ini_set('display_errors', '0');

// Development default. The package builder replaces this path for the hosting layout.
$bootstrap = getenv('GLIMPSE_BOOTSTRAP') ?: dirname(__DIR__, 2) . '/bootstrap.php';

try {
    $app = require $bootstrap;
    $app->run();
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    echo '{"error":{"code":"service_unavailable","message":"The service is temporarily unavailable."}}';
}
