<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\GitHub\GitHubConnectionService;
use Glimpse\Http\Cookies;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Repositories\GitHubPlatform;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryService;
use Glimpse\Services;
use Glimpse\Settings;
use Glimpse\Sharing\NewShare;
use Glimpse\Sharing\VisitorAccess;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SharingTest extends TestCase
{
    private PDO $pdo;
    private Settings $settings;
    private RepositoryTransport $transport;
    private FixedTransports $transports;
    private string $admin;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = new Settings(environment: 'test', origin: 'https://glimpse.example', database: [
            'dsn' => $dsn, 'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: '',
        ], shareEncryptionKey: base64_encode(str_repeat('k', 32)), githubTokenKey: Workspace::tokenKey(), githubTokenKeyId: Workspace::TOKEN_KEY_ID);
        $connection = new Connection($this->settings);
        $this->pdo = $connection->get();
        Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->transport = new RepositoryTransport();
        $this->transports = new FixedTransports($this->transport);
        $this->admin = Workspace::signIn($this->settings, github: $this->transports);
    }

    public function testCataloguePaginationDoesNotAutomaticallyShareRepositories(): void
    {
        $first = $this->data($this->send('GET', '/api/workspace/repositories', admin: true));
        self::assertSame('fixture/alpha', $first['items'][0]['fullName']);
        self::assertSame(2, $first['nextPage']);
        $last = $this->data($this->send('GET', '/api/workspace/repositories?page=2', admin: true));
        self::assertSame('fixture/beta', $last['items'][0]['fullName']);
        self::assertNull($last['nextPage']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM repositories'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
        self::assertSame(400, $this->send('GET', '/api/workspace/repositories?page=-1', admin: true)->getStatusCode());
        self::assertSame(401, $this->send('GET', '/api/workspace/repositories')->getStatusCode());
    }

    public function testHistoryAndDiffUseLiveShareAuthorizationEvenWhenCached(): void
    {
        $one = $this->createLink();
        $two = $this->createLink();
        $foreign = $this->createLink('beta');
        $visitor = $this->exchange($one);
        $other = $this->exchange($two);
        foreach (['history', 'commit'] as $kind) {
            $query = '?kind=' . $kind . '&sha=' . RepositoryTransport::SHA;
            self::assertSame(401, $this->send('GET', '/api/workspace/repositories/fixture/alpha/browse' . $query)->getStatusCode());
            self::assertSame(404, $this->send('GET', '/api/shares/' . $one['id'] . '/browse' . $query)->getStatusCode());
            self::assertSame(404, $this->send('GET', '/api/shares/' . $foreign['id'] . '/browse' . $query, visitor: $visitor)->getStatusCode());
            $warm = $this->send('GET', '/api/shares/' . $one['id'] . '/browse' . $query, visitor: $visitor);
            self::assertSame(200, $warm->getStatusCode(), (string) $warm->getBody());
            self::assertSame('private, no-store', $warm->getHeaderLine('Cache-Control'));
            self::assertSame('cache', $this->data($this->send('GET', '/api/shares/' . $two['id'] . '/browse' . $query, visitor: $other))['contentSource']);
        }
        $this->send('POST', '/api/workspace/shares/' . $one['id'] . '/revoke', [], admin: true);
        foreach (['history', 'commit'] as $kind) {
            $query = '?kind=' . $kind . '&sha=' . RepositoryTransport::SHA;
            self::assertSame(404, $this->send('GET', '/api/shares/' . $one['id'] . '/browse' . $query, visitor: $visitor)->getStatusCode());
            self::assertSame(200, $this->send('GET', '/api/shares/' . $two['id'] . '/browse' . $query, visitor: $other)->getStatusCode());
        }
        $this->pdo->exec('UPDATE share_links SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame(404, $this->send('GET', '/api/shares/' . $two['id'] . '/browse?kind=history', visitor: $other)->getStatusCode());
        $this->pdo->exec('UPDATE share_links SET expires_at = NULL');
        $this->pdo->exec('UPDATE repositories SET active = 0');
        self::assertSame(404, $this->send('GET', '/api/shares/' . $two['id'] . '/browse?kind=commit&sha=' . RepositoryTransport::SHA, visitor: $other)->getStatusCode());
    }

    public function testCreateStoresDigestAndEncryptedSecretWithoutExposingEitherInListing(): void
    {
        $link = $this->createLink();
        self::assertSame(32, strlen($link['id']));
        self::assertSame(64, strlen($link['secret']));
        self::assertSame(hash('sha256', $link['secret'], true), $this->scalar('SELECT secret_digest FROM share_links'));
        $ciphertext = $this->scalar('SELECT encrypted_secret FROM share_links');
        self::assertIsString($ciphertext);
        self::assertSame(93, strlen($ciphertext));
        self::assertStringNotContainsString($link['secret'], $ciphertext);
        self::assertSame(7, (int) $this->scalar('SELECT DATEDIFF(expires_at, created_at) FROM share_links'));
        $listing = $this->send('GET', '/api/workspace/repositories/fixture/alpha/shares', admin: true);
        $body = (string) $listing->getBody();
        self::assertStringNotContainsString($link['secret'], $body);
        self::assertStringNotContainsString('digest', $body);
        self::assertStringNotContainsString('encrypted_secret', $body);
        self::assertStringNotContainsString('https://', $body);
        self::assertSame('Review', $this->data($listing)['items'][0]['name']);
        self::assertTrue($this->data($listing)['storageConfigured']);
        self::assertSame('share_created', $this->scalar("SELECT action FROM audit_events WHERE action = 'share_created'"));
    }

    public function testRevealReturnsTheOriginalUrlOnlyToAnAdminWithCsrfAndCorrectOrigin(): void
    {
        $link = $this->createLink();
        $path = '/api/workspace/shares/' . $link['id'] . '/reveal';
        $visitor = $this->exchange($link);
        self::assertSame(401, $this->send('POST', $path, [])->getStatusCode());
        self::assertSame(401, $this->send('POST', $path, [], visitor: $visitor)->getStatusCode());
        self::assertSame(403, $this->send('POST', $path, [], admin: true, csrf: 'wrong')->getStatusCode());
        self::assertSame(403, $this->send('POST', $path, [], admin: true, origin: 'https://attacker.example')->getStatusCode());
        self::assertSame(405, $this->send('GET', $path, admin: true)->getStatusCode());
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_url_revealed'"));
        $this->transport->status = 503;
        for ($i = 0; $i < 2; $i++) {
            $response = $this->send('POST', $path, [], admin: true);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame($this->settings->origin . '/s/' . $link['id'] . '#' . $link['secret'], $this->data($response)['url']);
        }
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_url_revealed'"));
    }

    public function testRevealRejectsDisabledExpiredAndRevokedLinks(): void
    {
        $link = $this->createLink();
        $path = '/api/workspace/shares/' . $link['id'] . '/reveal';
        $this->pdo->exec('UPDATE repositories SET active = 0');
        self::assertSame(404, $this->send('POST', $path, [], admin: true)->getStatusCode());
        $this->pdo->exec('UPDATE repositories SET active = 1');
        $this->pdo->exec('UPDATE share_links SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame(404, $this->send('POST', $path, [], admin: true)->getStatusCode());
        $this->pdo->exec('UPDATE share_links SET expires_at = NULL');
        $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/revoke', [], admin: true);
        self::assertSame(404, $this->send('POST', $path, [], admin: true)->getStatusCode());
        self::assertNull($this->scalar('SELECT encrypted_secret FROM share_links'));
    }

    public function testMissingOrWrongKeyDoesNotBreakExistingVisitorAccessOrRevoke(): void
    {
        $link = $this->createLink();
        foreach (['' => 503, base64_encode(str_repeat('x', 32)) => 409] as $key => $status) {
            $this->settings = new Settings(
                environment: $this->settings->environment,
                origin: $this->settings->origin,
                database: $this->settings->database,
                shareEncryptionKey: $key,
                githubTokenKey: Workspace::tokenKey(),
                githubTokenKeyId: Workspace::TOKEN_KEY_ID,
            );
            $response = $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/reveal', [], admin: true);
            self::assertSame($status, $response->getStatusCode());
            self::assertStringNotContainsString($link['secret'], (string) $response->getBody());
            $visitor = $this->exchange($link);
            self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
            if ($key === '') {
                self::assertFalse($this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/shares', admin: true))['storageConfigured']);
                self::assertSame(503, $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Blocked'], admin: true)->getStatusCode());
                self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
            }
        }
        self::assertSame(200, $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/revoke', [], admin: true)->getStatusCode());
    }

    public function testLinksUseThePublicShareOriginAndOpenOnEveryAllowedDomain(): void
    {
        $this->settings = new Settings(
            environment: 'test',
            origin: $this->settings->origin,
            database: $this->settings->database,
            shareEncryptionKey: $this->settings->shareEncryptionKey,
            githubTokenKey: Workspace::tokenKey(),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
            workspaceOrigins: ['https://glimpse.example', 'https://glimpse.finity.example'],
            sharePublicOrigin: 'https://glimpse.finity.example',
            shareAliasOrigins: ['https://git.old.example'],
        );
        $link = $this->createLink();
        $url = 'https://glimpse.finity.example/s/' . $link['id'] . '#' . $link['secret'];
        $reveal = $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/reveal', [], admin: true, host: 'glimpse.example');
        self::assertSame($url, $this->data($reveal)['url']);
        // The same public ID and secret open through every public domain and the old share host, each with its own visitor exchange.
        foreach (['glimpse.example', 'glimpse.finity.example', 'git.old.example'] as $host) {
            $exchange = $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']], origin: 'https://' . $host, host: $host);
            self::assertSame(200, $exchange->getStatusCode(), (string) $exchange->getBody());
            self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $exchange->getHeaderLine('Set-Cookie'), $cookie));
            self::assertStringNotContainsString('Domain=', $exchange->getHeaderLine('Set-Cookie'));
            self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $cookie[1] ?? '', host: $host)->getStatusCode());
        }
        // The Origin must belong to the Host of this request, and the old share host never serves the workspace.
        $foreign = $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/reveal', [], admin: true, origin: 'https://glimpse.finity.example', host: 'glimpse.example');
        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame(403, $this->send('GET', '/api/workspace/repositories', admin: true, host: 'git.old.example')->getStatusCode());
        self::assertSame(421, $this->send('GET', '/api/workspace/repositories', admin: true, host: 'attacker.example')->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/workspace/repositories', admin: true, host: 'glimpse.finity.example')->getStatusCode());
    }

    public function testDeleteRequiresAdminCsrfOriginAndPriorRevocation(): void
    {
        $link = $this->createLink();
        $path = '/api/workspace/shares/' . $link['id'] . '/delete';
        $visitor = $this->exchange($link);
        self::assertSame(401, $this->send('POST', $path, [])->getStatusCode());
        self::assertSame(401, $this->send('POST', $path, [], visitor: $visitor)->getStatusCode());
        self::assertSame(403, $this->send('POST', $path, [], admin: true, csrf: 'wrong')->getStatusCode());
        self::assertSame(403, $this->send('POST', $path, [], admin: true, origin: 'https://attacker.example')->getStatusCode());
        self::assertSame(405, $this->send('GET', $path, admin: true)->getStatusCode());
        self::assertSame(409, $this->send('POST', $path, [], admin: true)->getStatusCode());
        $this->pdo->exec('UPDATE repositories SET active = 0');
        self::assertSame(409, $this->send('POST', $path, [], admin: true)->getStatusCode());
        $this->pdo->exec('UPDATE share_links SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), encrypted_secret = NULL');
        self::assertSame(409, $this->send('POST', $path, [], admin: true)->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_deleted'"));
        self::assertSame(200, $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/revoke', [], admin: true)->getStatusCode());
        self::assertSame(200, $this->send('POST', $path, [], admin: true)->getStatusCode());
        self::assertSame([], $this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/shares', admin: true))['items']);
    }

    public function testDeleteRemovesOnlyRevokedLinkAndItsGrantsWhilePreservingAuditAndOtherAccess(): void
    {
        $first = $this->createLink();
        $second = $this->createLink('beta');
        $visitor = $this->exchange($first);
        $this->exchange($second, $visitor);
        $this->send('POST', '/api/workspace/shares/' . $first['id'] . '/revoke', [], admin: true);
        $auditCount = (int) $this->scalar('SELECT COUNT(*) FROM audit_events');
        $this->transport->status = 503;
        // Delete needs neither GitHub, nor the token key, nor the share key.
        $original = $this->settings;
        $this->settings = new Settings(
            environment: $this->settings->environment,
            origin: $this->settings->origin,
            database: $this->settings->database,
        );
        $path = '/api/workspace/shares/' . $first['id'] . '/delete';
        $response = $this->send('POST', $path, [], admin: true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['status' => 'deleted'], $this->data($response));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
        self::assertSame($second['id'], $this->scalar('SELECT public_id FROM share_links'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM session_shares'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM sessions WHERE kind = 'visitor'"));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM repositories'));
        self::assertSame($auditCount + 1, (int) $this->scalar('SELECT COUNT(*) FROM audit_events'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_deleted' AND share_link_id IS NULL AND repository_id IS NOT NULL"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_revoked' AND share_link_id IS NULL"));
        self::assertSame(404, $this->send('POST', $path, [], admin: true)->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/shares/' . $first['id'] . '/exchange', ['secret' => $first['secret']])->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . $first['id'] . '/browse', visitor: $visitor)->getStatusCode());
        $this->transport->status = 200;
        $this->settings = $original;
        self::assertSame(200, $this->send('GET', '/api/shares/' . $second['id'] . '/browse', visitor: $visitor)->getStatusCode());
    }

    public function testConnectionProbeVerifiesTheSavedTokenAndRecordsTheResult(): void
    {
        $response = $this->send('POST', '/api/workspace/connections/github/probe', [], admin: true);
        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame(['connected', 'fixture-user', 1], [$data['status'], $data['githubLogin'], $data['credentialVersion']]);
        self::assertSame(GitHubConnectionService::mask(Workspace::token('owner')), $data['maskedToken']);
        self::assertStringNotContainsString(Workspace::token('owner'), (string) $response->getBody());
        $newConnection = (new Connection($this->settings))->get();
        $read = $newConnection->query("SELECT COUNT(*) FROM audit_events WHERE action = 'github_connection_probed' AND outcome = 'success'");
        self::assertNotFalse($read);
        self::assertSame(1, (int) $read->fetchColumn());
    }

    public function testAnotherUserCannotSeeOrChangeLinksOrRepositories(): void
    {
        $link = $this->createLink();
        $other = Workspace::signIn($this->settings, 'other.user', github: $this->transports);
        $as = fn(string $method, string $path, ?array $body = null): ResponseInterface => $this->send($method, $path, $body, workspace: $other);
        self::assertSame([], $this->data($as('GET', '/api/workspace/repositories/fixture/alpha/shares'))['items']);
        foreach (['reveal', 'revoke', 'delete'] as $action) {
            $response = $as('POST', '/api/workspace/shares/' . $link['id'] . '/' . $action, []);
            self::assertSame([404, 'share_unavailable'], [$response->getStatusCode(), $this->data($response)['error']['code']]);
            self::assertStringNotContainsString('fixture/alpha', (string) $response->getBody());
        }
        self::assertSame(404, $as('POST', '/api/workspace/shares/' . $link['id'] . '/permissions', ['branches' => ['main'], 'allowHistory' => true])->getStatusCode());
        // Disabling sharing registers the repository for this user only; the first owner's link keeps working.
        self::assertSame(200, $as('POST', '/api/workspace/repositories/fixture/alpha/availability', ['active' => false])->getStatusCode());
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM repositories WHERE external_id = 101"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM repositories WHERE external_id = 101 AND active = 1"));
        $visitor = $this->exchange($link);
        self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(409, $as('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Blocked'])->getStatusCode());
        self::assertSame(200, $as('POST', '/api/workspace/repositories/fixture/alpha/availability', ['active' => true])->getStatusCode());
        self::assertSame(201, $as('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Own link', 'visibility' => [['branch' => 'main', 'allFiles' => true]]])->getStatusCode());
        self::assertSame(['Review'], array_column($this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/shares', admin: true))['items'], 'name'));
        self::assertSame(['Own link'], array_column($this->data($as('GET', '/api/workspace/repositories/fixture/alpha/shares'))['items'], 'name'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM share_links l JOIN repositories r ON r.id = l.repository_id WHERE l.user_id <> r.user_id"));
    }

    public function testDisabledOwnersLinksStopServingEvenFromWarmCache(): void
    {
        $link = $this->createLink();
        $visitor = $this->exchange($link);
        self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=file&path=README.md', visitor: $visitor)->getStatusCode());
        $users = (new Services($this->settings))->accounts;
        $users->disable('owner');
        $calls = count($this->transport->paths);
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=file&path=README.md', visitor: $visitor)->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']])->getStatusCode());
        self::assertCount($calls, $this->transport->paths);
        $users->enable('owner');
        self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=file&path=README.md', visitor: $visitor)->getStatusCode());
    }

    public function testOneVisitorSessionOpensLinksOfSeveralOwnersWithoutMixingThem(): void
    {
        $first = $this->createLink();
        $other = Workspace::signIn($this->settings, 'other.user', github: $this->transports);
        $response = $this->send('POST', '/api/workspace/repositories/fixture/beta/shares', ['name' => 'Other', 'branches' => ['main'], 'visibility' => [['branch' => 'main', 'allFiles' => true]]], workspace: $other);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        $second = ['id' => (string) $data['publicId'], 'secret' => explode('#', (string) $data['url'])[1]];
        $visitor = $this->exchange($first);
        self::assertSame($visitor, $this->exchange($second, $visitor));
        foreach ([[$first, 'owner'], [$second, 'other.user']] as [$link, $owner]) {
            $this->transports->tokens = [];
            self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?refresh=1', visitor: $visitor)->getStatusCode());
            self::assertSame([Workspace::token($owner)], array_values(array_unique($this->transports->tokens)));
        }
        self::assertSame(200, $this->send('POST', '/api/workspace/shares/' . $second['id'] . '/revoke', [], workspace: $other)->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . $second['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/shares/' . $first['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(DISTINCT user_id) FROM api_cache'));
    }

    public function testVisitorUsesTheLinkOwnersTokenEvenWhenAnotherUserIsSignedInInTheSameBrowser(): void
    {
        $link = $this->createLink();
        $other = Workspace::signIn($this->settings, 'other.user', github: $this->transports);
        $visitor = $this->exchange($link);
        $this->transports->tokens = [];
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . $link['id'] . '/browse?refresh=1', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withQueryParams(['refresh' => '1'])
            ->withCookieParams([Cookies::VISITOR => $visitor, Cookies::WORKSPACE => $other]);
        self::assertSame(200, Application::create($this->settings, $this->transports)->handle($request)->getStatusCode());
        self::assertSame([Workspace::token('owner')], array_values(array_unique($this->transports->tokens)));
        // Without the owner's connection the link is unavailable instead of falling back to any other token.
        $this->pdo->exec("DELETE c FROM github_connections c JOIN users u ON u.id = c.user_id WHERE u.username = 'owner'");
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
    }

    public function testTwoLinksForSameRepositoryRemainIndependent(): void
    {
        $first = $this->createLink();
        $second = $this->createLink();
        $visitor = $this->exchange($first);
        $this->exchange($second, $visitor);
        $this->send('POST', '/api/workspace/shares/' . $first['id'] . '/revoke', [], admin: true);
        self::assertSame(404, $this->send('GET', '/api/shares/' . $first['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/shares/' . $second['id'] . '/browse', visitor: $visitor)->getStatusCode());
    }

    public function testVisitorReadsFilesWithoutAdminAndCannotChooseAnotherRepository(): void
    {
        $link = $this->createLink();
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse')->getStatusCode());
        $visitor = $this->exchange($link);
        $response = $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=file&path=README.md&owner=other&repository=beta', visitor: $visitor);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('fixture/alpha', $this->data($response)['repository']['fullName']);
        self::assertStringContainsString('# Private alpha', $this->data($response)['file']['content']);
        self::assertSame(401, $this->send('GET', '/api/workspace/repositories', visitor: $visitor)->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . str_repeat('f', 32) . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(400, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=file&path=../secret', visitor: $visitor)->getStatusCode());
        self::assertSame(400, $this->send('GET', '/api/shares/' . $link['id'] . '/browse?sha=not-a-sha', visitor: $visitor)->getStatusCode());
    }

    public function testOneVisitorCanUseTwoRepositoriesAndRevocationOnlyInvalidatesItsLink(): void
    {
        $alpha = $this->createLink();
        $beta = $this->createLink('beta', null);
        $visitor = $this->exchange($alpha);
        self::assertSame($visitor, $this->exchange($beta, $visitor));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM sessions WHERE kind = 'visitor'"));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM session_shares'));
        self::assertSame(200, $this->send('POST', '/api/workspace/shares/' . $alpha['id'] . '/revoke', [], admin: true)->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . $alpha['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/shares/' . $alpha['id'] . '/exchange', ['secret' => $alpha['secret']])->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/shares/' . $beta['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame('revoked', $this->data($this->send('GET', '/api/workspace/repositories/fixture/alpha/shares', admin: true))['items'][0]['status']);
    }

    public function testExpiryAndRepositoryDisableInvalidateExistingGrants(): void
    {
        $link = $this->createLink();
        $visitor = $this->exchange($link);
        $this->transport->status = 503;
        self::assertSame(200, $this->send('POST', '/api/workspace/repositories/fixture/alpha/availability', ['active' => false], admin: true)->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        $this->transport->status = 200;
        self::assertSame(409, $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Blocked'], admin: true)->getStatusCode());
        self::assertSame(200, $this->send('POST', '/api/workspace/repositories/fixture/alpha/availability', ['active' => true], admin: true)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        $this->pdo->exec('UPDATE share_links SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']])->getStatusCode());
    }

    public function testUpstreamAccessAndIdentityAreRecheckedEvenForHistoricalCommits(): void
    {
        $link = $this->createLink();
        $visitor = $this->exchange($link);
        $path = '/api/shares/' . $link['id'] . '/browse?sha=' . RepositoryTransport::SHA;
        self::assertSame(200, $this->send('GET', $path, visitor: $visitor)->getStatusCode());
        $this->transport->identityOffset = 1000;
        $this->pdo->exec('UPDATE api_cache SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame(403, $this->send('GET', $path, visitor: $visitor)->getStatusCode());
        self::assertSame(409, $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Wrong repository'], admin: true)->getStatusCode());
        $this->transport->status = 404;
        $denied = $this->send('GET', $path . '&refresh=1', visitor: $visitor);
        self::assertSame(502, $denied->getStatusCode());
        self::assertStringNotContainsString('Private upstream error', (string) $denied->getBody());
    }

    public function testRefreshResolvesBranchButFileNavigationAndExplicitCommitStayPinned(): void
    {
        $base = '/api/workspace/repositories/fixture/alpha/browse';
        self::assertSame(RepositoryTransport::SHA, $this->data($this->send('GET', $base, admin: true))['commitSha']);
        $this->transport->head = str_repeat('b', 40);
        self::assertSame(RepositoryTransport::SHA, $this->data($this->send('GET', $base . '?sha=' . RepositoryTransport::SHA . '&kind=file&path=README.md', admin: true))['commitSha']);
        self::assertSame(RepositoryTransport::SHA, $this->data($this->send('GET', $base, admin: true))['commitSha']);
        self::assertSame(str_repeat('b', 40), $this->data($this->send('GET', $base . '?refresh=1', admin: true))['commitSha']);
        self::assertSame(RepositoryTransport::SHA, $this->data($this->send('GET', $base . '?ref=' . RepositoryTransport::SHA, admin: true))['commitSha']);
    }

    public function testCachedFileStillRequiresLiveLinkAndSessionAuthorization(): void
    {
        $first = $this->createLink();
        $second = $this->createLink();
        $visitor = $this->exchange($first);
        $this->exchange($second, $visitor);
        $path = '/api/shares/' . $first['id'] . '/browse?kind=file&path=README.md';
        self::assertSame(200, $this->send('GET', $path, visitor: $visitor)->getStatusCode());
        $calls = count($this->transport->paths);
        self::assertSame('cache', $this->data($this->send('GET', $path, visitor: $visitor))['contentSource']);
        self::assertCount($calls, $this->transport->paths);
        $this->send('POST', '/api/workspace/shares/' . $first['id'] . '/revoke', [], admin: true);
        self::assertSame(404, $this->send('GET', $path, visitor: $visitor)->getStatusCode());
        self::assertCount($calls, $this->transport->paths);
        $other = str_replace($first['id'], $second['id'], $path);
        self::assertSame('cache', $this->data($this->send('GET', $other, visitor: $visitor))['contentSource']);
        self::assertSame(200, $this->send('POST', '/api/workspace/repositories/fixture/alpha/availability', ['active' => false], admin: true)->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_cache'));
        self::assertSame(404, $this->send('GET', $other, visitor: $visitor)->getStatusCode());
    }

    public function testWritesRequireCsrfAndCorrectOriginAndVisitorLogoutLeavesAdminSignedIn(): void
    {
        self::assertSame(403, $this->send('POST', '/api/workspace/repositories/fixture/alpha/shares', ['name' => 'Review'], admin: true, csrf: 'wrong')->getStatusCode());
        $link = $this->createLink();
        self::assertSame(403, $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']], origin: 'https://attacker.example')->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => str_repeat('0', 64)])->getStatusCode());
        $visitor = $this->exchange($link);
        self::assertSame(403, $this->send('POST', '/api/visitor/logout', [], visitor: $visitor)->getStatusCode());
        self::assertSame(200, $this->send('POST', '/api/visitor/logout', [], visitor: $visitor, csrf: Csrf::token(VisitorAccess::AUDIENCE, $visitor))->getStatusCode());
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/auth/session', admin: true)->getStatusCode());
    }

    public function testExpiredVisitorSessionCannotReadAndExchangeIsThrottled(): void
    {
        $link = $this->createLink();
        $visitor = $this->exchange($link);
        $this->pdo->exec("UPDATE sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE kind = 'visitor'");
        self::assertSame(404, $this->send('GET', '/api/shares/' . $link['id'] . '/browse', visitor: $visitor)->getStatusCode());
        $this->pdo->exec('DELETE FROM auth_throttles');
        for ($i = 0; $i < 30; $i++) {
            self::assertSame(404, $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => 'wrong'])->getStatusCode());
        }
        $response = $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']]);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('900', $response->getHeaderLine('Retry-After'));
    }

    public function testUpstreamRequestsNeverRunInsideDatabaseTransactions(): void
    {
        // Use the same Connection instance as the services to observe their transaction state.
        $connection = new Connection($this->settings);
        $this->transport->beforeRequest = static function () use ($connection): void {
            self::assertFalse($connection->get()->inTransaction());
        };
        $owner = (int) $this->scalar("SELECT id FROM users WHERE username = 'owner'");
        $services = new Services($this->settings, github: $this->transports, connection: $connection);
        $repositories = new RepositoryService($connection, $services->github->access($owner), new GitHubPlatform($services->github), new PreviewCaches($connection));
        $shares = $services->shares;
        $repositories->catalogue(1);
        $shares->create($owner, RepositoryRef::github('fixture', 'alpha'), NewShare::fromBody(['name' => 'Review', 'visibility' => [['branch' => 'main', 'allFiles' => true]]]));
        $repositories->browse(RepositoryRef::github('fixture', 'alpha'), BrowseQuery::fromQuery([]));
        $services->sharing->setActive($owner, RepositoryRef::github('fixture', 'alpha'), false);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_leases'));
    }

    public function testBranchAndHistoryPermissionsCannotBeBypassedWithCachedContent(): void
    {
        $full = $this->createLink();
        $limited = $this->createLink(history: false);
        $visitor = $this->exchange($full);
        $this->exchange($limited, $visitor);
        $base = '/api/shares/' . $limited['id'] . '/browse';
        $choices = $this->data($this->send('GET', $base . '?kind=branches', visitor: $visitor));
        self::assertSame(['main'], $choices['items']);
        self::assertFalse($choices['allowHistory']);
        $ancestor = str_repeat('b', 40);
        $foreign = str_repeat('f', 40);
        foreach (['file&path=README.md', 'directory', 'search&q=app', 'history', 'commit'] as $kind) {
            $admin = '/api/workspace/repositories/fixture/alpha/browse?kind=' . $kind . '&sha=' . $foreign;
            self::assertSame(200, $this->send('GET', $admin, admin: true)->getStatusCode());
            foreach ([$limited, $full] as $link) {
                $denied = $this->send('GET', '/api/shares/' . $link['id'] . '/browse?kind=' . $kind . '&branch=main&sha=' . $foreign, visitor: $visitor);
                self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());
            }
            self::assertSame(403, $this->send('GET', $base . '?kind=' . $kind . '&sha=' . $ancestor, visitor: $visitor)->getStatusCode());
        }
        foreach (['branch=private', 'ref=private', 'branch=Main', 'branch=refs%2Fheads%2Fmain'] as $query) {
            self::assertSame(403, $this->send('GET', $base . '?' . $query, visitor: $visitor)->getStatusCode());
        }
        self::assertSame(200, $this->send('GET', $base . '?kind=file&path=README.md&branch=main', visitor: $visitor)->getStatusCode());
        self::assertSame(200, $this->send('GET', '/api/shares/' . $full['id'] . '/browse?kind=commit&sha=' . $ancestor, visitor: $visitor)->getStatusCode());
    }

    public function testChangingPermissionsKeepsUrlAndSharedCacheButAppliesToExistingSessions(): void
    {
        $link = $this->createLink();
        $other = $this->createLink();
        $visitor = $this->exchange($link);
        $this->exchange($other, $visitor);
        $base = '/api/shares/' . $link['id'] . '/browse';
        self::assertSame(200, $this->send('GET', $base . '?kind=file&path=README.md', visitor: $visitor)->getStatusCode());
        $cache = (int) $this->scalar('SELECT COUNT(*) FROM api_cache');
        $edit = '/api/workspace/shares/' . $link['id'] . '/permissions';
        $policy = ['branches' => ['feature/preview'], 'allowHistory' => false, 'visibility' => [['branch' => 'feature/preview', 'allFiles' => true]]];
        self::assertSame(401, $this->send('POST', $edit, $policy, visitor: $visitor)->getStatusCode());
        self::assertSame(403, $this->send('POST', $edit, $policy, admin: true, csrf: 'wrong')->getStatusCode());
        self::assertSame(403, $this->send('POST', $edit, $policy, admin: true, origin: 'https://attacker.example')->getStatusCode());
        self::assertSame(200, $this->send('POST', $edit, $policy, admin: true)->getStatusCode());
        self::assertGreaterThanOrEqual($cache, (int) $this->scalar('SELECT COUNT(*) FROM api_cache'));
        self::assertSame(403, $this->send('GET', $base . '?branch=main', visitor: $visitor)->getStatusCode());
        $data = $this->data($this->send('GET', $base, visitor: $visitor));
        self::assertSame('feature/preview', $data['branch']);
        self::assertFalse($data['allowHistory']);
        self::assertSame('cache', $this->data($this->send('GET', '/api/shares/' . $other['id'] . '/browse?kind=file&path=README.md', visitor: $visitor))['contentSource']);
        self::assertSame($this->settings->origin . '/s/' . $link['id'] . '#' . $link['secret'], $this->data($this->send('POST', '/api/workspace/shares/' . $link['id'] . '/reveal', [], admin: true))['url']);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_permissions_updated'"));
        $this->send('POST', '/api/workspace/shares/' . $link['id'] . '/revoke', [], admin: true);
        self::assertSame(404, $this->send('POST', $edit, $policy, admin: true)->getStatusCode());
    }

    public function testPermissionValidationDefaultsAndLinksWithoutRules(): void
    {
        $create = '/api/workspace/repositories/fixture/alpha/shares';
        foreach ([[], ['main', 'main'], ['refs/heads/..'], ['~bad'], array_fill(0, 51, 'main')] as $branches) {
            self::assertSame(400, $this->send('POST', $create, ['name' => 'Invalid', 'branches' => $branches], admin: true)->getStatusCode());
        }
        self::assertSame(400, $this->send('POST', $create, ['name' => 'Invalid', 'allowHistory' => 'false'], admin: true)->getStatusCode());
        self::assertSame(502, $this->send('POST', $create, ['name' => 'Missing', 'branches' => ['missing']], admin: true)->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
        self::assertSame(201, $this->send('POST', $create, ['name' => 'Default', 'visibility' => [['branch' => 'main', 'allFiles' => true]]], admin: true)->getStatusCode());
        $listing = $this->data($this->send('GET', $create, admin: true));
        self::assertSame(['main'], $listing['items'][0]['branches']);
        self::assertFalse($listing['items'][0]['allowHistory']);
        $link = $this->createLink();
        $visitor = $this->exchange($link);
        $base = '/api/shares/' . $link['id'] . '/browse';
        self::assertSame(200, $this->send('GET', $base . '?kind=branches', visitor: $visitor)->getStatusCode());
        // A link without branches, or in the all-files mode of releases before per-branch rules, serves nothing.
        $this->pdo->exec('UPDATE share_links SET allowed_branches = NULL');
        self::assertSame(404, $this->send('GET', $base . '?kind=branches', visitor: $visitor)->getStatusCode());
        $this->pdo->exec("UPDATE share_links SET allowed_branches = '[\"main\"]', allow_history = TRUE, visibility_mode = 'legacy_all_files'");
        self::assertSame(404, $this->send('GET', $base . '?kind=commit&sha=' . str_repeat('f', 40), visitor: $visitor)->getStatusCode());
        $this->pdo->exec("UPDATE share_links SET visibility_mode = 'branch_policies'");
        $this->pdo->exec("UPDATE share_links SET allowed_branches = '[]'");
        self::assertSame(400, $this->send('GET', $base, visitor: $visitor)->getStatusCode());
    }

    public function testBranchMovementRechecksReachabilityAndCurrentFilesOnly(): void
    {
        $history = $this->createLink();
        $current = $this->createLink(history: false);
        $visitor = $this->exchange($history);
        $this->exchange($current, $visitor);
        $base = '/api/shares/' . $history['id'] . '/browse?kind=file&path=README.md&branch=main&sha=' . str_repeat('b', 40);
        self::assertSame(200, $this->send('GET', $base, visitor: $visitor)->getStatusCode());
        $this->transport->head = str_repeat('e', 40);
        $this->transport->diverged = true;
        self::assertSame(403, $this->send('GET', $base . '&refresh=1', visitor: $visitor)->getStatusCode());
        self::assertSame(403, $this->send('GET', '/api/shares/' . $current['id'] . '/browse?sha=' . RepositoryTransport::SHA, visitor: $visitor)->getStatusCode());
        self::assertSame(str_repeat('e', 40), $this->data($this->send('GET', '/api/shares/' . $current['id'] . '/browse?refresh=1', visitor: $visitor))['commitSha']);
        $this->transport->status = 503;
        self::assertSame(502, $this->send('GET', $base . '&refresh=1', visitor: $visitor)->getStatusCode());
    }

    public function testSearchUsesPinnedTreeAcrossQueriesAndSkipsLinksAndSubmodules(): void
    {
        $link = $this->createLink(history: false);
        $visitor = $this->exchange($link);
        $base = '/api/shares/' . $link['id'] . '/browse?kind=search&branch=main&q=';
        $result = $this->data($this->send('GET', $base . 'DOCS', visitor: $visitor));
        self::assertSame(['docs/čitaj me.txt'], array_column($result['search']['items'], 'path'));
        self::assertFalse($result['search']['limited']);
        $calls = count($this->transport->paths);
        $result = $this->data($this->send('GET', $base . 'app', visitor: $visitor));
        self::assertCount(2, $result['search']['items']);
        self::assertCount($calls, $this->transport->paths);
        self::assertSame([], $this->data($this->send('GET', $base . 'nonexistent', visitor: $visitor))['search']['items']);
        self::assertSame(400, $this->send('GET', $base, visitor: $visitor)->getStatusCode());
        $this->pdo->exec('DELETE FROM api_cache');
        $this->transport->truncatedTree = true;
        self::assertTrue($this->data($this->send('GET', $base . 'app', visitor: $visitor))['search']['limited']);
    }

    /** @return array{id: string, secret: string} */
    private function createLink(string $repository = 'alpha', ?int $days = 7, bool $history = true): array
    {
        $response = $this->send('POST', '/api/workspace/repositories/fixture/' . $repository . '/shares', ['name' => 'Review', 'expiresInDays' => $days, 'branches' => ['main'], 'allowHistory' => $history, 'visibility' => [['branch' => 'main', 'allFiles' => true]]], admin: true);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertIsString($data['url']);
        self::assertIsString($data['publicId']);
        return ['id' => $data['publicId'], 'secret' => explode('#', $data['url'])[1]];
    }

    /** @param array{id: string, secret: string} $link */
    private function exchange(array $link, ?string $visitor = null): string
    {
        $response = $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']], visitor: $visitor);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('; Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches));
        self::assertArrayHasKey(1, $matches);
        if (!isset($matches[1])) {
            throw new \RuntimeException('Visitor cookie was not issued.');
        }
        return $matches[1];
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, bool $admin = false, ?string $visitor = null, ?string $csrf = null, ?string $origin = null, ?string $workspace = null, ?string $host = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($host !== null) {
            $request = $request->withHeader('Host', $host);
        }
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);
        if ($method === 'POST') {
            $request = $request->withHeader('Origin', $origin ?? $this->settings->origin)->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        $cookies = [];
        if ($admin) {
            $cookies[Cookies::WORKSPACE] = $this->admin;
            $csrf ??= Csrf::token(UserAuth::AUDIENCE, $this->admin);
        }
        if ($workspace !== null) {
            $cookies[Cookies::WORKSPACE] = $workspace;
            $csrf ??= Csrf::token(UserAuth::AUDIENCE, $workspace);
        }
        if ($visitor !== null) {
            $cookies[Cookies::VISITOR] = $visitor;
        }
        $request = $request->withCookieParams($cookies);
        if ($csrf !== null) {
            $request = $request->withHeader('X-CSRF-Token', $csrf);
        }
        return Application::create($this->settings, $this->transports)->handle($request);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }
}
