<?php
declare(strict_types=1);

// Local smoke-test router only. It mirrors deploy/public/.htaccess but does not execute or verify Apache rules.
$root = realpath(getcwd());
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $root . '/api/index.php';
    return;
}
if (preg_match('#(^|/)(\.|private(?:/|$)|backend(?:/|$)|vendor(?:/|$)|config(?:/|$)|website(?:/|$))#i', $path)
    || str_contains($path, '\\') || str_contains($path, "\0") || str_ends_with(strtolower($path), '.php')) {
    http_response_code(404);
    return;
}
$page = static function (string $file) use ($root): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile($root . '/' . $file);
};
if ($path === '/') {
    $page('index.html');
    return;
}
$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
if (preg_match('#^/(?:login/?|app(?:/.*)?|admin(?:/.*)?|s/[^/]+(?:/.*)?)$#D', $path) === 1) {
    $page('workspace.html');
    return;
}
http_response_code(404);
