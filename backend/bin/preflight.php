<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$required = ['curl', 'mbstring', 'PDO', 'pdo_mysql', 'openssl', 'json'];
$missing = array_values(array_filter($required, static fn(string $extension): bool => !extension_loaded($extension)));
$supported = version_compare(phpversion(), '8.5.0', '>=') && version_compare(phpversion(), '8.6.0', '<');
$curl = extension_loaded('curl') ? curl_version() : false;
echo json_encode([
    'phpVersion' => PHP_VERSION,
    'supportedTarget' => $supported,
    'missingExtensions' => $missing,
    'memoryLimit' => ini_get('memory_limit'),
    'maxExecutionTime' => ini_get('max_execution_time'),
    'argon2id' => defined('PASSWORD_ARGON2ID'),
    'opcacheEnabled' => ini_get('opcache.enable'),
    'curlTlsBackend' => $curl !== false ? $curl['ssl_version'] : 'unavailable',
    'databaseAndGitHubTested' => false,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
exit($supported && $missing === [] ? 0 : 1);
