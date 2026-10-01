<?php

declare(strict_types=1);

namespace Glimpse\Tests\Integration;

use Glimpse\Application;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\UserAuth;
use Glimpse\Http\Cookies;
use Glimpse\Settings;
use Glimpse\Tests\Fixtures\FixedTransports;
use Glimpse\Tests\Fixtures\RepositoryTransport;
use Glimpse\Tests\Fixtures\Workspace;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class LinkPolicyTest extends TestCase
{
    private const REPO = '/api/workspace/repositories/fixture/alpha';
    private Settings $settings;
    private PDO $pdo;
    private RepositoryTransport $transport;
    private FixedTransports $transports;
    private string $owner;

    protected function setUp(): void
    {
        $dsn = getenv('GLIMPSE_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('A dedicated MySQL/MariaDB test database is required.');
        }
        if (getenv('GLIMPSE_TEST_DB_RESET') !== '1' || !preg_match('/dbname=glimpse_test(?:;|$)/', $dsn)) {
            self::fail('Refusing to reset a non-test database.');
        }
        $this->settings = new Settings(
            environment: 'test',
            origin: 'https://glimpse.example',
            database: ['dsn' => $dsn,
                'username' => getenv('GLIMPSE_TEST_DB_USER') ?: 'root', 'password' => getenv('GLIMPSE_TEST_DB_PASSWORD') ?: ''],
            shareEncryptionKey: base64_encode(str_repeat('k', 32)),
            githubTokenKey: Workspace::tokenKey(),
            githubTokenKeyId: Workspace::TOKEN_KEY_ID,
        );
        $this->pdo = Workspace::migrate($this->settings);
        Workspace::reset($this->pdo);
        $this->transport = new RepositoryTransport();
        $this->transports = new FixedTransports($this->transport);
        $this->owner = Workspace::signIn($this->settings, github: $this->transports);
    }

    public function testNewLinksNeedExplicitRulesForEveryBranchAndCopyThemAtomically(): void
    {
        $shares = self::REPO . '/shares';
        $response = $this->send('POST', $shares, ['name' => 'No rules']);
        self::assertSame([400, 'visibility_required'], [$response->getStatusCode(), $this->code($response)]);
        $response = $this->send('POST', $shares, ['name' => 'No profile', 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        self::assertSame([409, 'visibility_profile_changed'], [$response->getStatusCode(), $this->code($response)]);
        self::assertSame(1, $this->profile('main', [], 0)['version']);
        $response = $this->send('POST', $shares, ['name' => 'Empty', 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        self::assertSame([422, 'visibility_empty'], [$response->getStatusCode(), $this->code($response)]);
        $this->profile('main', [['action' => 'select', 'path' => 'README.md'], ['action' => 'select', 'path' => 'docs']], 1);
        $response = $this->send('POST', $shares, ['name' => 'Stale', 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        self::assertSame([409, 'visibility_profile_changed'], [$response->getStatusCode(), $this->code($response)]);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM share_links'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM share_branch_policies'));
        // History is an ordinary permission for selected files too; the visitor then gets filtered history.
        $this->link(['allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => 2]]]);
        self::assertSame(['branch_policies', 1, 1], [$this->scalar('SELECT visibility_mode FROM share_links'), (int) $this->scalar('SELECT policy_version FROM share_links'),
            (int) $this->scalar('SELECT allow_history FROM share_links')]);
        self::assertSame(['selected', 2, 2], [$this->scalar('SELECT mode FROM share_branch_policies'), (int) $this->scalar('SELECT selected_count FROM share_branch_policies'),
            (int) $this->scalar('SELECT COUNT(*) FROM share_visibility_files')]);
        $listing = $this->data($this->send('GET', $shares))['items'][0];
        self::assertSame(
            [1, [['branch' => 'main', 'mode' => 'selected', 'selectedCount' => 2, 'profileVersion' => 2]]],
            [$listing['policyVersion'], $listing['policies']],
        );
        // The summary names the first selected paths, so the link form can show what the selection shares.
        self::assertSame(
            ['branch' => 'main', 'mode' => 'selected', 'version' => 2, 'selectedCount' => 2, 'preview' => ['README.md', 'docs/čitaj me.txt']],
            $this->data($this->send('GET', self::REPO . '/visibility?branch=main&summary=1')),
        );
        self::assertSame(
            ['branch' => 'feature/preview', 'mode' => null, 'version' => 0, 'selectedCount' => 0, 'preview' => []],
            $this->data($this->send('GET', self::REPO . '/visibility?branch=feature%2Fpreview&summary=1')),
        );
    }

    public function testVisitorsReceiveOnlySelectedFilesAndNothingAboutHiddenOnes(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md'], ['action' => 'select', 'path' => 'docs']], 0);
        // The owner's raw cache already holds a hidden file; filtering must still apply to every visitor response.
        self::assertSame(200, $this->send('GET', self::REPO . '/browse?kind=file&path=App.xaml')->getStatusCode());
        $link = $this->link(['visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $root = $this->visit($link, $visitor, '');
        self::assertSame(['docs', 'README.md'], array_column($root['entries'], 'name'));
        self::assertSame(1, $root['policyVersion']);
        self::assertSame(['docs/čitaj me.txt'], array_column($this->visit($link, $visitor, '?path=docs')['entries'], 'path'));
        self::assertSame(200, $this->visitRaw($link, $visitor, '?kind=file&path=README.md')->getStatusCode());
        $hidden = $this->visitRaw($link, $visitor, '?kind=file&path=App.xaml');
        $missing = $this->visitRaw($link, $visitor, '?kind=file&path=nope.md');
        self::assertSame([$missing->getStatusCode(), $this->code($missing)], [$hidden->getStatusCode(), $this->code($hidden)]);
        self::assertSame('upstream_not_found', $this->code($hidden));
        self::assertStringNotContainsString('App.xaml', (string) $hidden->getBody());
        self::assertSame([[], null], [$this->visit($link, $visitor, '?kind=search&q=App')['search']['items'], $this->visit($link, $visitor, '?kind=search&q=App')['search']['nextPage']]);
        self::assertSame(['README.md'], array_column($this->visit($link, $visitor, '?kind=search&q=md')['search']['items'], 'path'));
        foreach (['?kind=history', '?kind=commit&sha=' . RepositoryTransport::SHA] as $query) {
            self::assertSame([403, 'history_not_allowed'], [$this->visitRaw($link, $visitor, $query)->getStatusCode(), $this->code($this->visitRaw($link, $visitor, $query))]);
        }
        self::assertSame(403, $this->visitRaw($link, $visitor, '?kind=file&path=README.md&sha=' . str_repeat('b', 40))->getStatusCode());
        $branches = $this->visit($link, $visitor, '?kind=branches');
        self::assertSame([['main'], false], [$branches['items'], $branches['allowHistory']]);
        // The all-files mode of releases before per-branch rules opens nothing, not every file.
        $this->pdo->exec("UPDATE share_links SET visibility_mode = 'legacy_all_files'");
        self::assertSame(404, $this->visitRaw($link, $visitor, '')->getStatusCode());
    }

    public function testProfileEditsReachAnExistingLinkOnlyWhenApplied(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md']], 0);
        $link = $this->link(['visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $this->profile('main', [['action' => 'select', 'path' => 'App.xaml']], 1);
        self::assertSame('upstream_not_found', $this->code($this->visitRaw($link, $visitor, '?kind=file&path=App.xaml')));
        $apply = '/api/workspace/shares/' . $link['id'] . '/visibility';
        $stale = $this->send('POST', $apply, ['expectedPolicyVersion' => 1, 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        self::assertSame([409, 'visibility_profile_changed'], [$stale->getStatusCode(), $this->code($stale)]);
        self::assertSame(200, $this->send('POST', $apply, ['expectedPolicyVersion' => 1, 'visibility' => [['branch' => 'main', 'profileVersion' => 2]]])->getStatusCode());
        // The same visitor session sees the new rules on its next request; the URL and secret are unchanged.
        self::assertSame(200, $this->visitRaw($link, $visitor, '?kind=file&path=App.xaml')->getStatusCode());
        self::assertSame(2, $this->visit($link, $visitor, '')['policyVersion']);
        self::assertSame($this->settings->origin . '/s/' . $link['id'] . '#' . $link['secret'], $this->data($this->send('POST', '/api/workspace/shares/' . $link['id'] . '/reveal', []))['url']);
        $again = $this->send('POST', $apply, ['expectedPolicyVersion' => 1, 'visibility' => [['branch' => 'main', 'allFiles' => true]]]);
        self::assertSame([409, 'share_policy_changed'], [$again->getStatusCode(), $this->code($again)]);
        self::assertSame(400, $this->send('POST', $apply, ['expectedPolicyVersion' => 2, 'visibility' => [['branch' => 'feature/preview', 'allFiles' => true]]])->getStatusCode());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_events WHERE action = 'share_visibility_applied'"));
    }

    public function testEditingBranchesKeepsOrReplacesRulesAndHistory(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md']], 0);
        $link = $this->link(['visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $edit = '/api/workspace/shares/' . $link['id'] . '/permissions';
        self::assertSame(400, $this->send('POST', $edit, ['branches' => ['main', 'feature/preview'], 'allowHistory' => false,
            'visibility' => [['branch' => 'main', 'keep' => true], ['branch' => 'feature/preview', 'keep' => true]]])->getStatusCode());
        self::assertSame(200, $this->send('POST', $edit, ['branches' => ['main', 'feature/preview'], 'allowHistory' => false, 'expectedPolicyVersion' => 1,
            'visibility' => [['branch' => 'main', 'keep' => true], ['branch' => 'feature/preview', 'allFiles' => true]]])->getStatusCode());
        self::assertSame(200, $this->visitRaw($link, $visitor, '?branch=feature%2Fpreview&kind=file&path=App.xaml')->getStatusCode());
        self::assertSame('upstream_not_found', $this->code($this->visitRaw($link, $visitor, '?branch=main&kind=file&path=App.xaml')));
        $history = $this->send('POST', $edit, ['branches' => ['main', 'feature/preview'], 'allowHistory' => true,
            'visibility' => [['branch' => 'main', 'keep' => true], ['branch' => 'feature/preview', 'keep' => true]]]);
        self::assertSame(200, $history->getStatusCode(), (string) $history->getBody());
        self::assertTrue($this->visit($link, $visitor, '?branch=main&kind=history')['history']['filtered']);
        self::assertArrayNotHasKey('filtered', $this->visit($link, $visitor, '?branch=feature%2Fpreview&kind=history')['history']);
        self::assertSame(200, $this->send('POST', $edit, ['branches' => ['feature/preview'], 'allowHistory' => true,
            'visibility' => [['branch' => 'feature/preview', 'keep' => true]]])->getStatusCode());
        self::assertSame('feature/preview', $this->scalar('SELECT GROUP_CONCAT(branch) FROM share_branch_policies'));
        self::assertSame(403, $this->visitRaw($link, $visitor, '?branch=main')->getStatusCode());
        self::assertSame(200, $this->visitRaw($link, $visitor, '?branch=feature%2Fpreview&kind=history')->getStatusCode());
        self::assertSame(4, (int) $this->scalar('SELECT policy_version FROM share_links'));
        $stale = $this->send('POST', $edit, ['branches' => ['feature/preview'], 'allowHistory' => true, 'expectedPolicyVersion' => 1,
            'visibility' => [['branch' => 'feature/preview', 'keep' => true]]]);
        self::assertSame([409, 'share_policy_changed'], [$stale->getStatusCode(), $this->code($stale)]);
    }

    public function testABranchWithoutRulesMustChooseThemExplicitly(): void
    {
        $link = $this->link(['visibility' => [['branch' => 'main', 'allFiles' => true]]]);
        $this->pdo->exec('DELETE FROM share_branch_policies');
        $edit = '/api/workspace/shares/' . $link['id'] . '/permissions';
        self::assertSame(400, $this->send('POST', $edit, ['branches' => ['main'], 'allowHistory' => true, 'visibility' => [['branch' => 'main', 'keep' => true]]])->getStatusCode());
        self::assertSame(200, $this->send('POST', $edit, ['branches' => ['main'], 'allowHistory' => true, 'visibility' => [['branch' => 'main', 'allFiles' => true]]])->getStatusCode());
        self::assertSame(['branch_policies', '["main"]', 'all'], [$this->scalar('SELECT visibility_mode FROM share_links'), $this->scalar('SELECT allowed_branches FROM share_links'),
            $this->scalar('SELECT mode FROM share_branch_policies')]);
    }

    public function testChangedOrInconsistentRulesNeverServeContent(): void
    {
        $link = $this->link(['visibility' => [['branch' => 'main', 'allFiles' => true]]]);
        $visitor = $this->exchange($link);
        $this->transport->beforeRequest = function (): void {
            $path = end($this->transport->paths);
            if (is_string($path) && str_contains($path, '/contents/App.xaml.cs')) {
                $this->pdo->exec('UPDATE share_links SET policy_version = policy_version + 1');
            }
        };
        $changed = $this->visitRaw($link, $visitor, '?kind=file&path=App.xaml.cs');
        self::assertSame([409, 'share_policy_changed'], [$changed->getStatusCode(), $this->code($changed)]);
        self::assertStringNotContainsString('Glimpse', (string) $changed->getBody());
        $this->transport->beforeRequest = null;
        self::assertSame(200, $this->visitRaw($link, $visitor, '?kind=file&path=App.xaml.cs')->getStatusCode());
        $this->pdo->exec('DELETE FROM share_branch_policies');
        self::assertSame(404, $this->visitRaw($link, $visitor, '')->getStatusCode());
        $this->link(['visibility' => [['branch' => 'main', 'allFiles' => true]]]);
        $this->pdo->exec('UPDATE share_links SET visibility_schema_version = 2');
        self::assertSame(404, $this->visitRaw($link, $visitor, '')->getStatusCode());
    }

    public function testSelectedFilesHistoryListsOnlySharedChangesWithoutMessagesOrHiddenNames(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md'], ['action' => 'select', 'path' => 'docs']], 0);
        $link = $this->link(['allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $this->transport->history = self::history();
        self::assertTrue($this->visit($link, $visitor, '?kind=branches')['allowHistory']);
        $response = $this->visitRaw($link, $visitor, '?kind=history');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $history = $this->data($response)['history'];
        self::assertSame([self::sha('b', 1), self::sha('c', 2), self::sha('d', 3), self::sha('d', 5)], array_column($history['items'], 'sha'));
        self::assertSame(['Changes to shared files'], array_values(array_unique(array_column($history['items'], 'message'))));
        self::assertSame([''], array_values(array_unique(array_column($history['items'], 'author'))));
        self::assertSame([true, null, false, false], [$history['filtered'], $history['nextCursor'], $history['incomplete'], $history['largeCommitsSkipped']]);
        foreach (['App.xaml', 'secret', 'private/', 'Hidden Author', 'Rotate'] as $hidden) {
            self::assertStringNotContainsString($hidden, (string) $response->getBody());
        }
        // Commit details contain only shared files and totals over them; a one-sided rename keeps only the shared name.
        $detail = $this->visit($link, $visitor, '?kind=commit&sha=' . self::sha('b', 1))['detail'];
        self::assertSame(['README.md'], array_column($detail['files'], 'path'));
        self::assertSame(['additions' => 1, 'deletions' => 1, 'total' => 2], $detail['stats']);
        self::assertSame(['Changes to shared files', ''], [$detail['commit']['message'], $detail['commit']['author']]);
        $moved = $this->visitRaw($link, $visitor, '?kind=commit&sha=' . self::sha('c', 2));
        self::assertSame([['path' => 'docs/čitaj me.txt', 'previousPath' => null, 'status' => 'added', 'additions' => 0, 'deletions' => 0,
            'patch' => null, 'patchState' => 'restricted']], $this->data($moved)['detail']['files']);
        foreach (['TOP SECRET', 'secret/plan.md', 'Move'] as $hidden) {
            self::assertStringNotContainsString($hidden, (string) $moved->getBody());
        }
        $away = $this->visit($link, $visitor, '?kind=commit&sha=' . self::sha('d', 3))['detail']['files'];
        self::assertSame([['README.md', 'removed', 'restricted']], array_map(static fn(array $file): array => [$file['path'], $file['status'], $file['patchState']], $away));
        // A commit that changes only hidden files, or only copies a shared one, looks exactly like a missing commit.
        foreach ([RepositoryTransport::SHA, self::sha('d', 4)] as $sha) {
            $hidden = $this->visitRaw($link, $visitor, '?kind=commit&sha=' . $sha);
            self::assertSame([502, 'upstream_not_found'], [$hidden->getStatusCode(), $this->code($hidden)]);
            self::assertStringNotContainsString('App.xaml', (string) $hidden->getBody());
        }
        // File history of an exact shared file, of a shared folder, and of a hidden path.
        self::assertSame([self::sha('b', 1), self::sha('d', 3)], array_column($this->visit($link, $visitor, '?kind=history&path=README.md')['history']['items'], 'sha'));
        self::assertSame([self::sha('c', 2), self::sha('d', 5)], array_column($this->visit($link, $visitor, '?kind=history&path=docs')['history']['items'], 'sha'));
        self::assertSame('upstream_not_found', $this->code($this->visitRaw($link, $visitor, '?kind=history&path=App.xaml')));
        // Earlier versions of shared paths open at reachable revisions; hidden paths stay hidden there too.
        self::assertSame(200, $this->visitRaw($link, $visitor, '?kind=file&path=README.md&sha=' . self::sha('b', 1))->getStatusCode());
        self::assertSame('upstream_not_found', $this->code($this->visitRaw($link, $visitor, '?kind=file&path=App.xaml&sha=' . self::sha('b', 1))));
        self::assertSame(['docs', 'README.md'], array_column($this->visit($link, $visitor, '?sha=' . self::sha('b', 1))['entries'], 'name'));
        // The owner's own History stays complete.
        $owner = $this->data($this->send('GET', self::REPO . '/browse?kind=history'))['history'];
        self::assertSame(array_keys(self::history()), array_column($owner['items'], 'sha'));
        self::assertSame(['Rotate secret in App.xaml', 'Hidden Author'], [$owner['items'][0]['message'], $owner['items'][0]['author']]);
    }

    public function testSelectedFilesHistoryPagesThroughACursorBoundToTheRules(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md']], 0);
        $link = $this->link(['allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $history = [];
        for ($i = 0; $i < 65; $i++) {
            $history[$i === 0 ? RepositoryTransport::SHA : self::sha('b', $i)] = self::change('README.md');
        }
        $this->transport->history = $history;
        [$sizes, $found, $incomplete] = $this->scan($link, $visitor, '');
        self::assertSame([[30, 30, 5], array_keys($history), 0], [$sizes, $found, $incomplete]);
        self::assertSame(400, $this->visitRaw($link, $visitor, '?kind=history&cursor=' . rawurlencode('https://api.github.com/x'))->getStatusCode());
        self::assertSame(400, $this->visitRaw($link, $visitor, '?kind=history&cursor=1.30.1')->getStatusCode());
        $stale = $this->visitRaw($link, $visitor, '?kind=history&cursor=2.0.9');
        self::assertSame([409, 'share_policy_changed'], [$stale->getStatusCode(), $this->code($stale)]);
    }

    public function testRareSharedChangesAreFoundInBoundedIncompleteSteps(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md']], 0);
        $link = $this->link(['allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $history = [RepositoryTransport::SHA => self::change('App.xaml')];
        for ($i = 1; $i < 120; $i++) {
            $history[self::sha('c', $i)] = self::change($i === 110 ? 'README.md' : 'App.xaml');
        }
        $this->transport->history = $history;
        $before = count($this->transport->paths);
        [$sizes, $found, $incomplete] = $this->scan($link, $visitor, '');
        self::assertSame([self::sha('c', 110)], $found);
        self::assertGreaterThanOrEqual(2, $incomplete);
        self::assertContains(0, $sizes);
        // Every request stays within its GitHub budget: 40 calls for the scan plus the usual access checks.
        self::assertLessThanOrEqual(count($sizes) * 45, count($this->transport->paths) - $before);
        // A second pass reads the same commits from the owner's cache.
        $before = count($this->transport->paths);
        self::assertSame([self::sha('c', 110)], $this->scan($link, $visitor, '')[1]);
        self::assertLessThan(10, count($this->transport->paths) - $before);
    }

    public function testVeryLargeCommitsAreLeftOutOfTheListButTheirSharedChangesStayReadable(): void
    {
        $this->profile('main', [['action' => 'select', 'path' => 'README.md']], 0);
        $link = $this->link(['allowHistory' => true, 'visibility' => [['branch' => 'main', 'profileVersion' => 1]]]);
        $visitor = $this->exchange($link);
        $files = [];
        for ($i = 0; $i < 310; $i++) {
            $files[] = self::change($i === 305 ? 'README.md' : 'generated/' . $i . '.txt')['files'][0];
        }
        $this->transport->history = [RepositoryTransport::SHA => ['files' => $files], self::sha('b', 1) => self::change('README.md')];
        $history = $this->visit($link, $visitor, '?kind=history')['history'];
        self::assertSame([[self::sha('b', 1)], true], [array_column($history['items'], 'sha'), $history['largeCommitsSkipped']]);
        $detail = $this->visit($link, $visitor, '?kind=commit&sha=' . RepositoryTransport::SHA)['detail'];
        self::assertSame([['README.md'], ['additions' => 1, 'deletions' => 0, 'total' => 1], null], [array_column($detail['files'], 'path'), $detail['stats'], $detail['nextPage']]);
        self::assertStringNotContainsString('generated/', json_encode($detail, JSON_THROW_ON_ERROR));
    }

    /** Follows nextCursor until the history ends.
     * @param array{id: string, secret: string} $link
     * @return array{list<int>, list<string>, int}
     */
    private function scan(array $link, string $visitor, string $path): array
    {
        $sizes = [];
        $found = [];
        $incomplete = 0;
        $cursor = '';
        do {
            $page = $this->visit($link, $visitor, '?kind=history&path=' . rawurlencode($path) . '&cursor=' . rawurlencode($cursor))['history'];
            $sizes[] = count($page['items']);
            $found = [...$found, ...array_column($page['items'], 'sha')];
            $incomplete += $page['incomplete'] ? 1 : 0;
            $cursor = $page['nextCursor'];
        } while (is_string($cursor) && count($sizes) < 20);
        return [$sizes, $found, $incomplete];
    }

    private static function sha(string $prefix, int $number): string
    {
        return $prefix . str_pad(dechex($number), 39, '0', STR_PAD_LEFT);
    }

    /** @return array{files: list<array<string, mixed>>} */
    private static function change(string $path): array
    {
        return ['files' => [['filename' => $path, 'status' => 'modified', 'additions' => 1, 'deletions' => 0, 'patch' => "@@ -0,0 +1 @@\n+x"]]];
    }

    /** Newest first: hidden-only, mixed, renamed in from a hidden path, renamed away, copied away, shared folder.
     * @return array<string, array{files: list<array<string, mixed>>, message: string}>
     */
    private static function history(): array
    {
        $file = static fn(string $name, string $status = 'modified', ?string $previous = null, string $patch = "@@ -1 +1 @@\n-old\n+new"): array =>
            ['filename' => $name, 'status' => $status, 'additions' => 1, 'deletions' => 1, 'patch' => $patch] + ($previous === null ? [] : ['previous_filename' => $previous]);
        return [
            RepositoryTransport::SHA => ['message' => 'Rotate secret in App.xaml', 'files' => [$file('App.xaml')]],
            self::sha('b', 1) => ['message' => 'Mention App.xaml.cs secrets', 'files' => [$file('README.md'), $file('App.xaml.cs')]],
            self::sha('c', 2) => ['message' => 'Move secret/plan.md', 'files' => [$file('docs/čitaj me.txt', 'renamed', 'secret/plan.md', "@@ -1 +1 @@\n-TOP SECRET\n+public")]],
            self::sha('d', 3) => ['message' => 'Hide README in private/', 'files' => [$file('private/README.md', 'renamed', 'README.md')]],
            self::sha('d', 4) => ['message' => 'Copy README to private/copy.md', 'files' => [$file('private/copy.md', 'copied', 'README.md')]],
            self::sha('d', 5) => ['message' => 'Edit docs', 'files' => [$file('docs/čitaj me.txt')]],
        ];
    }

    /** @param list<array{action: string, path: string}> $operations
     *  @return array<string, mixed>
     */
    private function profile(string $branch, array $operations, int $version): array
    {
        $response = $this->send('PUT', self::REPO . '/visibility', ['branch' => $branch, 'expectedVersion' => $version,
            'sourceSha' => RepositoryTransport::SHA, 'mode' => 'selected', 'operations' => $operations]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    /** @param array<string, mixed> $body
     *  @return array{id: string, secret: string}
     */
    private function link(array $body): array
    {
        $response = $this->send('POST', self::REPO . '/shares', $body + ['name' => 'Review']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        return ['id' => (string) $data['publicId'], 'secret' => explode('#', (string) $data['url'])[1]];
    }

    /** @param array{id: string, secret: string} $link */
    private function exchange(array $link): string
    {
        $response = $this->send('POST', '/api/shares/' . $link['id'] . '/exchange', ['secret' => $link['secret']], session: '');
        self::assertSame(1, preg_match('/glimpse_visitor=([a-f0-9]{64})/', $response->getHeaderLine('Set-Cookie'), $matches));
        return $matches[1] ?? '';
    }

    /** @param array{id: string, secret: string} $link
     *  @return array<string, mixed>
     */
    private function visit(array $link, string $visitor, string $query): array
    {
        $response = $this->visitRaw($link, $visitor, $query);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->data($response);
    }

    /** @param array{id: string, secret: string} $link */
    private function visitRaw(array $link, string $visitor, string $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/shares/' . $link['id'] . '/browse' . $query, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $params);
        return Application::create($this->settings, $this->transports)->handle($request->withQueryParams($params)->withCookieParams([Cookies::VISITOR => $visitor]));
    }

    private function code(ResponseInterface $response): string
    {
        $error = $this->data($response)['error'] ?? null;
        return is_array($error) ? (string) ($error['code'] ?? '') : '';
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function scalar(string $sql): mixed
    {
        $read = $this->pdo->query($sql);
        self::assertNotFalse($read);
        return $read->fetchColumn();
    }

    /** @param array<string, mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null, ?string $session = null): ResponseInterface
    {
        $session ??= $this->owner;
        $request = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query)->withCookieParams($session === '' ? [] : [Cookies::WORKSPACE => $session]);
        if ($body !== null) {
            $request = $request->withHeader('Origin', $this->settings->origin)->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', Csrf::token(UserAuth::AUDIENCE, $session));
            $request->getBody()->write(json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR));
            $request->getBody()->rewind();
        }
        return Application::create($this->settings, $this->transports)->handle($request);
    }
}
