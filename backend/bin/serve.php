<?php

declare(strict_types=1);

// Router for the loopback-only PHP development server; never publish this file.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && ($path === '/api' || str_starts_with($path, '/api/'))) {
    require dirname(__DIR__) . '/public/api/index.php';
    return;
}
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo '{"error":{"code":"not_found","message":"API route not found."}}';
