<?php

declare(strict_types=1);

use Glimpse\Application;
use Glimpse\Auth\UserContext;
use Glimpse\Database\Connection;
use Glimpse\Http\Cookies;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Sharing\NewShare;
use Glimpse\Source\Transport;
use Glimpse\Tests\Fixtures\FakeTransport;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use Slim\Psr7\Factory\ServerRequestFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$dsn = getenv('GLIMPSE_TEST_DB_DSN') ?: '';
if (PHP_SAPI !== 'cli' || getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
    fwrite(STDERR, "A dedicated glimpse_test database and explicit reset flag are required.\n");
    exit(1);
}
$settings = new Settings(
    environment: 'test',
    origin: 'https://glimpse.example',
    database: ['dsn' => $dsn,
        'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: ''],
    shareEncryptionKey: base64_encode(str_repeat('k', 32)),
    githubTokenKey: Workspace::tokenKey(),
    githubTokenKeyId: Workspace::TOKEN_KEY_ID,
);
$connection = new Connection($settings);
$pdo = $connection->get();

function preview(Settings $settings, Transport $transport, string $publicId, int $visitor): float
{
    $start = hrtime(true);
    $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . $publicId . '/browse')
        ->withQueryParams(['kind' => 'file', 'path' => 'README.md'])
        ->withCookieParams([Cookies::VISITOR => hash('sha256', 'benchmark-visitor-' . $visitor)]);
    $application = Application::create($settings, new FixedTransports($transport));
    $response = $application->handle($request);
    // A real request ends and closes its database connection. Here the application's reference cycles would keep it
    // open, and 10 workers x 20 requests exceed MariaDB's default of 151 connections, so release it like a request end.
    unset($application);
    gc_collect_cycles();
    $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== 200 || !is_array($body) || ($body['file']['content'] ?? '') === '') {
        throw new RuntimeException('Preview benchmark failed.');
    }
    return (hrtime(true) - $start) / 1000000;
}

$worker = $argv[1] ?? null;
if ($worker !== null) {
    if (!ctype_digit($worker) || (int) $worker > 9) {
        exit(1);
    }
    $read = $pdo->prepare("SELECT public_id FROM share_links WHERE name = 'Cache benchmark' LIMIT 1");
    $read->execute();
    $publicId = $read->fetchColumn();
    if (!is_string($publicId)) {
        exit(1);
    }
    // Empty transport makes any unexpected GitHub call fail the benchmark.
    $transport = new FakeTransport([]);
    $samples = [];
    for ($i = 0; $i < 20; $i++) {
        $samples[] = preview($settings, $transport, $publicId, (int) $worker);
    }
    echo json_encode(['samples' => $samples, 'peakBytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
    exit;
}

Workspace::migrate($settings);
Workspace::reset($pdo);
$transport = new RepositoryTransport();
$ownerId = Workspace::user($pdo, 'benchmark');
$services = new Services($settings, github: new FixedTransports($transport), connection: $connection);
$connections = $services->github;
$connections->save(new UserContext($ownerId, 'benchmark', 0, 1, [UserContext::WORKSPACE], ''), Workspace::token('benchmark'), 0, 'benchmark');
$link = $services->shares->create($ownerId, RepositoryRef::github('fixture', 'alpha'), NewShare::fromBody(['name' => 'Cache benchmark', 'visibility' => [['branch' => 'main', 'allFiles' => true]]]));
for ($i = 0; $i < 10; $i++) {
    $pdo->prepare("INSERT INTO sessions (token_digest, kind, audience, created_at, expires_at) VALUES (?, 'visitor', 'visitor', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))")
        ->execute([hash('sha256', hash('sha256', 'benchmark-visitor-' . $i), true)]);
    $pdo->prepare('INSERT INTO session_shares (session_id, share_link_id, created_at) SELECT ?, id, UTC_TIMESTAMP(6) FROM share_links WHERE public_id = ?')
        ->execute([(int) $pdo->lastInsertId(), $link['publicId']]);
}
$services->caches->invalidateUser($ownerId);
$coldCalls = count($transport->paths);
$coldMs = preview($settings, $transport, $link['publicId'], 0);
$coldCalls = count($transport->paths) - $coldCalls;
$before = $services->caches->status($ownerId);
$workers = [];
$samples = [];
$peakBytes = 0;
try {
    for ($i = 0; $i < 10; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, (string) $i], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start benchmark worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes[1], $pipes[2]];
    }
    foreach ($workers as [$process, $stdout, $stderr]) {
        $output = stream_get_contents($stdout);
        $errors = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $status = proc_close($process);
        // The worker's own error explains a failure in CI logs; it never contains secrets of the synthetic test data.
        if ($status !== 0 || $output === false) {
            throw new RuntimeException('Benchmark worker failed with exit code ' . $status . ': '
                . substr(trim($errors . "\n" . $output), 0, 2000));
        }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_array($result['samples'] ?? null) || !is_int($result['peakBytes'] ?? null)) {
            throw new RuntimeException('Invalid benchmark result.');
        }
        foreach ($result['samples'] as $sample) {
            if (!is_float($sample) && !is_int($sample)) {
                throw new RuntimeException('Invalid benchmark sample.');
            }
            $samples[] = $sample;
        }
        $peakBytes = max($peakBytes, $result['peakBytes']);
    }
} finally {
    foreach ($workers as [$process, $stdout, $stderr]) {
        if (is_resource($stdout)) {
            fclose($stdout);
        }
        if (is_resource($stderr)) {
            fclose($stderr);
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
sort($samples);
if (count($samples) !== 200) {
    throw new RuntimeException('Missing benchmark samples.');
}
$after = $services->caches->status($ownerId);
echo json_encode(['environment' => 'Local PHP CLI + Slim + MariaDB; synthetic GitHub, no HTTP proxy or browser timings',
    'workers' => 10, 'requests' => count($samples), 'p50Ms' => round($samples[99], 2), 'p95Ms' => round($samples[189], 2),
    'peakPhpBytesPerWorker' => $peakBytes, 'coldFixtureMs' => round($coldMs, 2), 'coldUpstreamCalls' => $coldCalls,
    'warmUpstreamCalls' => $after->upstreamCalls - $before->upstreamCalls, 'cache' => $after], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
