<?php

declare(strict_types=1);

use Glimpse\Cache\PreviewCache;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\GitHub\GitHubCachePolicy;
use Glimpse\Settings;
use Glimpse\Source\HttpResult;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Tests\Fixtures\FakeTransport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$dsn = getenv('GLIMPSE_TEST_DB_DSN') ?: '';
if (PHP_SAPI !== 'cli' || getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
    exit(1);
}
$id = $argv[1] ?? '';
$userId = $argv[2] ?? '';
if (!ctype_digit($id) || !ctype_digit($userId)) {
    exit(1);
}
$connection = new Connection(new Settings(environment: 'test', database: ['dsn' => $dsn,
    'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '']));
$cache = new PreviewCache($connection, new SourceAccess(Provider::GitHub, (int) $userId, 1, new FakeTransport([]), Provider::GITHUB_INSTANCE, new GitHubCachePolicy()), new PreviewCaches($connection));
try {
    for ($i = 0; $i < 36; $i++) {
        $cache->put(
            $cache->key('parallel-' . $id . '-' . $i, 1),
            PreviewCache::scope(Provider::GitHub, 'fixture/alpha'),
            null,
            new HttpResult(200, [], str_repeat('x', 400000)),
            3600,
        );
    }
    echo "Cache writer completed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_debug_type($error) . "\n");
    exit(1);
}
