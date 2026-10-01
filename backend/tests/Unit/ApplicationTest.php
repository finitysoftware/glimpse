<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Application;
use Glimpse\Http\Cookies;
use Glimpse\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApplicationTest extends TestCase
{
    public function testHealthDoesNotRequireDatabaseOrExposeConfiguration(): void
    {
        $response = Application::create(new Settings())->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/health'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function privateRoutes(): iterable
    {
        yield 'preview cache' => ['/api/workspace/preview-cache', 401];
        yield 'session' => ['/api/auth/session', 401];
        yield 'GitHub connection' => ['/api/workspace/connections/github', 401];
        yield 'catalogue' => ['/api/workspace/repositories', 401];
        yield 'workspace preview' => ['/api/workspace/repositories/owner/repo/browse', 401];
        yield 'share list' => ['/api/workspace/repositories/owner/repo/shares', 401];
        yield 'removed administrator API' => ['/api/admin/diagnostics', 404];
        yield 'admin session' => ['/api/admin/auth/session', 401];
        yield 'visitor preview' => ['/api/shares/' . str_repeat('a', 32) . '/browse', 404];
        yield 'unknown API' => ['/api/missing', 404];
        yield 'API root' => ['/api', 404];
        yield 'configuration' => ['/api/config/settings.local.php', 404];
    }

    #[DataProvider('privateRoutes')]
    public function testAnonymousRoutesReturnSafeJson(string $path, int $status): void
    {
        $response = Application::create(new Settings())->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        self::assertSame($status, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('password', (string) $response->getBody());
    }

    public function testMissingDatabaseFailsClosedForPlausibleSession(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/workspace/preview-cache')
            ->withCookieParams([Cookies::WORKSPACE => str_repeat('a', 64)]);
        $response = Application::create(new Settings())->handle($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('dsn', (string) $response->getBody());
    }

    public function testCrossOriginLoginIsRejectedBeforeDatabaseAccess(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/login')
            ->withHeader('Origin', 'https://attacker.example')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{"username":"owner","password":"test"}');
        $response = Application::create(new Settings())->handle($request);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('origin_rejected', (string) $response->getBody());
    }

    public function testMissingDatabaseFailsClosedForVisitorWithPlausibleCookie(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . str_repeat('a', 32) . '/browse')
            ->withCookieParams([Cookies::VISITOR => str_repeat('b', 64)]);
        $response = Application::create(new Settings())->handle($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('dsn', (string) $response->getBody());
    }

    public function testAnonymousProbeIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/workspace/connections/github/probe')
            ->withHeader('Origin', 'https://app.glimpse.example')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{}');
        $request->getBody()->rewind();
        $response = Application::create(new Settings())->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testOversizedLoginIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/login')
            ->withHeader('Origin', 'https://app.glimpse.example')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(str_repeat(' ', 4097));
        $request->getBody()->rewind();
        self::assertSame(413, Application::create(new Settings())->handle($request)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function visibilityRoutes(): iterable
    {
        yield 'GitHub repository' => ['/api/workspace/repositories/owner/repo'];
        yield 'GitLab project' => ['/api/workspace/gitlab/projects/42'];
    }

    #[DataProvider('visibilityRoutes')]
    public function testVisibilityChangesMayUseTheLargerBodyOnEveryPlatform(string $repository): void
    {
        // 100 changes, the most one save accepts, with ordinary paths: above the 4 KiB of other writes, below 32 KiB.
        $body = '{"branch":"main","operations":[' . implode(',', array_fill(0, 100, '{"action":"select","path":"src/components/' . str_repeat('x', 50) . '.tsx"}')) . ']}';
        self::assertGreaterThan(4096, strlen($body));
        self::assertLessThan(32768, strlen($body));
        $send = static function (string $method, string $path) use ($body): int {
            $request = (new ServerRequestFactory())->createServerRequest($method, $path)
                ->withHeader('Origin', 'https://app.glimpse.example')->withHeader('Content-Type', 'application/json');
            $request->getBody()->write($body);
            $request->getBody()->rewind();
            return Application::create(new Settings())->handle($request)->getStatusCode();
        };
        // Past the size check, the request only lacks a session.
        self::assertSame(401, $send('PUT', $repository . '/visibility'));
        // Every other write keeps the small limit.
        self::assertSame(413, $send('POST', $repository . '/shares'));
    }

    public function testProductionRequiresHttps(): void
    {
        $this->expectException(\RuntimeException::class);
        new Settings(origin: 'http://example.com');
    }

    public function testMaintenanceAnswersEveryApiRouteWithRetryableUnavailability(): void
    {
        $settings = new Settings(maintenance: true);
        foreach (['/api/health', '/api/workspace/repositories', '/api/shares/' . str_repeat('a', 32) . '/browse'] as $path) {
            $response = Application::create($settings)->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
            self::assertSame(503, $response->getStatusCode());
            self::assertStringContainsString('"maintenance"', (string) $response->getBody());
            self::assertSame('300', $response->getHeaderLine('Retry-After'));
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }
        $login = $this->post(new Settings(maintenance: true), '/api/auth/login', 'app.glimpse.example', 'https://app.glimpse.example');
        self::assertSame(503, $login->getStatusCode());
        // The operator's own address may run acceptance checks before maintenance ends.
        $operator = new Settings(maintenance: true, maintenanceAllowIps: ['203.0.113.7']);
        $factory = new ServerRequestFactory();
        self::assertSame(401, Application::create($operator)->handle($factory->createServerRequest('GET', '/api/auth/session', ['REMOTE_ADDR' => '203.0.113.7']))->getStatusCode());
        self::assertSame(503, Application::create($operator)->handle($factory->createServerRequest('GET', '/api/auth/session', ['REMOTE_ADDR' => '203.0.113.8']))->getStatusCode());
    }

    public function testUnknownOrForwardedHostIsRejectedExceptForHealth(): void
    {
        $app = Application::create(new Settings());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/workspace/preview-cache');
        self::assertSame(401, $app->handle($request->withHeader('Host', 'app.glimpse.example'))->getStatusCode());
        self::assertSame(401, $app->handle($request->withHeader('Host', 'APP.glimpse.example:443'))->getStatusCode());
        foreach (['attacker.example', 'app.glimpse.example:8443', 'app.glimpse.example.attacker.example'] as $host) {
            $response = $app->handle($request->withHeader('Host', $host)->withHeader('X-Forwarded-Host', 'app.glimpse.example'));
            self::assertSame(421, $response->getStatusCode());
            self::assertStringContainsString('host_rejected', (string) $response->getBody());
        }
        // Slim drops the port from the Host header; the raw HTTP_HOST decides.
        $local = Application::create(new Settings(environment: 'test', origin: 'http://127.0.0.1:8091'));
        $factory = new ServerRequestFactory();
        self::assertSame(401, $local->handle($factory->createServerRequest('GET', '/api/auth/session', ['HTTP_HOST' => '127.0.0.1:8091'])->withHeader('Host', '127.0.0.1'))->getStatusCode());
        self::assertSame(421, $local->handle($factory->createServerRequest('GET', '/api/auth/session', ['HTTP_HOST' => '127.0.0.1:8092'])->withHeader('Host', '127.0.0.1:8091'))->getStatusCode());
        $health = (new ServerRequestFactory())->createServerRequest('GET', '/api/health')->withHeader('Host', 'attacker.example');
        self::assertSame(200, $app->handle($health)->getStatusCode());
        $post = $this->post(new Settings(), '/api/auth/login', 'attacker.example', 'https://attacker.example');
        self::assertSame(421, $post->getStatusCode());
    }

    public function testMutationOriginMustBeTheOriginOfTheRequestHost(): void
    {
        $settings = new Settings(workspaceOrigins: ['https://app.glimpse.example', 'https://glimpse.example']);
        $path = '/api/workspace/connections/github/probe';
        self::assertSame(401, $this->post($settings, $path, 'glimpse.example', 'https://glimpse.example')->getStatusCode());
        self::assertSame(401, $this->post($settings, $path, 'app.glimpse.example', 'https://app.glimpse.example')->getStatusCode());
        foreach ([['glimpse.example', 'https://app.glimpse.example'], ['app.glimpse.example', 'https://glimpse.example'],
            ['glimpse.example', 'http://glimpse.example'], ['glimpse.example', '']] as [$host, $origin]) {
            $response = $this->post($settings, $path, $host, $origin);
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('origin_rejected', (string) $response->getBody());
        }
    }

    public function testShareAliasHostServesVisitorRoutesOnly(): void
    {
        $settings = new Settings(shareAliasOrigins: ['https://old.example']);
        $app = Application::create($settings);
        $factory = new ServerRequestFactory();
        $visitor = $app->handle($factory->createServerRequest('GET', '/api/shares/' . str_repeat('a', 32) . '/browse')->withHeader('Host', 'old.example'));
        self::assertSame(404, $visitor->getStatusCode());
        self::assertStringContainsString('share_unavailable', (string) $visitor->getBody());
        foreach (['/api/workspace/repositories', '/api/auth/session', '/api/missing'] as $path) {
            $response = $app->handle($factory->createServerRequest('GET', $path)->withHeader('Host', 'old.example'));
            self::assertSame(403, $response->getStatusCode());
            self::assertStringContainsString('host_rejected', (string) $response->getBody());
        }
        self::assertSame(403, $this->post($settings, '/api/auth/login', 'old.example', 'https://old.example')->getStatusCode());
        self::assertSame(401, $app->handle($factory->createServerRequest('GET', '/api/auth/session')->withHeader('Host', 'app.glimpse.example'))->getStatusCode());
    }

    public function testConfiguredOriginsDefaultToThePrimaryOrigin(): void
    {
        $settings = new Settings(origin: 'https://glimpse.example');
        self::assertSame(['https://glimpse.example'], $settings->workspaceOrigins);
        self::assertSame('https://glimpse.example', $settings->sharePublicOrigin);
        self::assertFalse($settings->maintenance);
        $both = new Settings(workspaceOrigins: ['https://app.glimpse.example', 'https://glimpse.example'], sharePublicOrigin: 'https://glimpse.example');
        self::assertSame('https://glimpse.example', $both->originForHost('glimpse.example'));
        self::assertNull($both->originForHost('glimpse.example:444'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOrigins(): iterable
    {
        yield 'path' => [['origin' => 'https://example.com/app']];
        yield 'default port' => [['origin' => 'https://example.com:443']];
        yield 'uppercase' => [['origin' => 'https://Example.com']];
        yield 'primary outside workspace list' => [['workspaceOrigins' => ['https://glimpse.example']]];
        yield 'share origin outside allowed hosts' => [['sharePublicOrigin' => 'https://glimpse.example']];
        yield 'duplicate host' => [['shareAliasOrigins' => ['https://app.glimpse.example']]];
        yield 'insecure alias in production' => [['shareAliasOrigins' => ['http://old.example']]];
        yield 'invalid maintenance address' => [['maintenanceAllowIps' => ['203.0.113.0/24']]];
        yield 'invalid port' => [['workspaceOrigins' => ['https://app.glimpse.example', 'https://glimpse.example:99999']]];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidOrigins')]
    public function testInvalidOriginConfigurationIsRejected(array $arguments): void
    {
        $this->expectException(\RuntimeException::class);
        new Settings(...$arguments);
    }

    public function testSettingsFileConfiguresDomainsAndMaintenanceFlag(): void
    {
        foreach (['GLIMPSE_ORIGIN', 'GLIMPSE_WORKSPACE_ORIGINS', 'GLIMPSE_SHARE_PUBLIC_ORIGIN', 'GLIMPSE_SHARE_ALIAS_ORIGINS', 'GLIMPSE_MAINTENANCE', 'GLIMPSE_ENV'] as $name) {
            if (getenv($name) !== false) {
                self::markTestSkipped($name . ' overrides the settings file.');
            }
        }
        $directory = sys_get_temp_dir() . '/glimpse-settings-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory . '/settings.local.php', "<?php return ['workspace_origins' => ['https://glimpse.example', 'https://www.glimpse.example'],"
                . " 'share_public_origin' => 'https://www.glimpse.example', 'share_alias_origins' => ['https://app.glimpse.example'],"
                . " 'platform_admin_origins' => ['https://admin.glimpse.example']];");
            $settings = Settings::load($directory);
            self::assertSame('https://glimpse.example', $settings->origin);
            self::assertSame(['https://glimpse.example', 'https://www.glimpse.example'], $settings->workspaceOrigins);
            self::assertSame('https://www.glimpse.example', $settings->sharePublicOrigin);
            self::assertTrue($settings->isShareAlias('https://app.glimpse.example'));
            self::assertNull($settings->originForHost('admin.glimpse.example'), 'The key of the removed admin host is ignored.');
            self::assertFalse($settings->maintenance);
            touch($directory . '/maintenance.flag');
            self::assertTrue(Settings::load($directory)->maintenance);
        } finally {
            @unlink($directory . '/maintenance.flag');
            @unlink($directory . '/settings.local.php');
            @rmdir($directory);
        }
    }

    private function post(Settings $settings, string $path, string $host, string $origin): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)->withHeader('Host', $host)
            ->withHeader('Content-Type', 'application/json');
        if ($origin !== '') {
            $request = $request->withHeader('Origin', $origin);
        }
        $request->getBody()->write('{}');
        $request->getBody()->rewind();
        return Application::create($settings)->handle($request);
    }
}
