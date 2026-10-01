<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Audit\DailyMetrics;
use Glimpse\Auth\Csrf;
use Glimpse\Auth\Throttle;
use Glimpse\Database\Connection;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\Repositories\BranchChoices;
use Glimpse\Repositories\BrowseAccess;
use Glimpse\Repositories\BrowseQuery;
use Glimpse\Repositories\Preview;
use Glimpse\Repositories\RepositoryRef;
use Glimpse\Repositories\RepositoryServices;
use PDO;
use SensitiveParameter;

/**
 * Visitors of access links: no account, one visitor session per browser that may hold links of several owners.
 * Each request derives the link owner from the link itself, never from a workspace session in the same browser.
 */
final readonly class VisitorAccess
{
    public const AUDIENCE = 'visitor';
    public const TTL_SECONDS = 86400;

    /** SQL joins of a link `l` to its repository `r` and its active owner `u`; always used together with SERVES_SQL. */
    public const SERVES_JOIN = "JOIN repositories r ON r.id = l.repository_id AND r.user_id = l.user_id JOIN users u ON u.id = l.user_id AND u.status = 'active'";
    /** SQL: a link that serves its visitors now - active, on a shared repository of an active owner. Opening a link, every
     * visitor request and the last check before a response use the same rule.
     */
    public const SERVES_SQL = LinkStatus::ACTIVE_SQL . ' AND r.active = 1';

    public function __construct(
        private Connection $connection,
        private RepositoryServices $repositories,
        private LinkPolicies $policies,
        private DailyMetrics $metrics,
        private Throttle $throttle,
    ) {}

    /**
     * Grants the link to this browser's visitor session, or to a new one when it has none ($token '').
     *
     * @return array{token: string, csrfToken: string}
     */
    public function exchange(string $publicId, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $token, string $address): array
    {
        $this->throttle->limit('share:global', '', 300);
        $this->throttle->limit('share:address', $address, 30);
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            throw UnavailableLink::failure();
        }
        $pdo = $this->connection->get();
        $token = Transaction::run($pdo, function () use ($pdo, $publicId, $secret, $token): string {
            $read = $pdo->prepare('SELECT l.id, l.user_id, l.secret_digest FROM share_links l ' . self::SERVES_JOIN . '
                WHERE l.public_id = ? AND ' . self::SERVES_SQL . ' FOR UPDATE');
            $read->execute([$publicId]);
            $link = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($link) || !hash_equals((string) $link['secret_digest'], hash('sha256', $secret, true))) {
                throw UnavailableLink::failure();
            }
            $session = $pdo->prepare("SELECT id FROM sessions WHERE token_digest = ? AND kind = 'visitor' AND audience = 'visitor'
                AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE");
            $session->execute([hash('sha256', $token, true)]);
            $sessionId = $session->fetchColumn();
            if ($sessionId === false) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare("INSERT INTO sessions (token_digest, kind, audience, created_at, expires_at) VALUES (?, 'visitor', 'visitor', UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL " . self::TTL_SECONDS . ' SECOND))')
                    ->execute([hash('sha256', $token, true)]);
                $sessionId = $pdo->lastInsertId();
            }
            // One visitor session may hold links of several owners; each request derives its owner from the link.
            $pdo->prepare('INSERT IGNORE INTO session_shares (session_id, share_link_id, created_at) VALUES (?, ?, UTC_TIMESTAMP(6))')->execute([$sessionId, $link['id']]);
            $this->metrics->increment('link_opened', (int) $link['user_id']);
            return $token;
        });
        return ['token' => $token, 'csrfToken' => Csrf::token(self::AUDIENCE, $token)];
    }

    /** The link owner is derived from the link itself, never from a signed-in workspace session in the same browser.
     * A disabled owner's links stop serving on the next request, before any cache read.
     */
    public function authorize(string $publicId, #[SensitiveParameter] string $token): AuthorizedLink
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            throw UnavailableLink::failure();
        }
        $read = $this->connection->get()->prepare("SELECT l.id, l.user_id, l.visibility_mode, l.visibility_schema_version, l.policy_version,
            r.provider, r.instance_url, r.owner, r.name, r.external_id, l.allowed_branches, l.allow_history FROM sessions s
            JOIN session_shares ss ON ss.session_id = s.id JOIN share_links l ON l.id = ss.share_link_id " . self::SERVES_JOIN . "
            WHERE s.token_digest = ? AND s.kind = 'visitor' AND s.audience = 'visitor' AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND l.public_id = ?
            AND " . self::SERVES_SQL);
        $read->execute([hash('sha256', $token, true), $publicId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw UnavailableLink::failure();
        }
        $userId = (int) $row['user_id'];
        $linkId = (int) $row['id'];
        // A link serves content only with its branches and exactly one readable rule per branch; anything else denies access.
        if ($row['visibility_mode'] !== 'branch_policies' || (int) $row['visibility_schema_version'] !== 1 || $row['allowed_branches'] === null) {
            throw UnavailableLink::failure();
        }
        $branches = BrowseAccess::stored((string) $row['allowed_branches']);
        $policies = $this->policies->forVisitor($userId, $linkId);
        foreach ($branches as $branch) {
            if (!isset($policies[$branch])) {
                throw UnavailableLink::failure();
            }
        }
        $policy = new SharePolicy($this->connection, $linkId, $userId, (int) $row['policy_version'], $policies);
        return new AuthorizedLink(
            $userId,
            RepositoryRef::fromRow($row),
            (string) $row['instance_url'],
            (int) $row['external_id'],
            new BrowseAccess($branches, (bool) $row['allow_history'], $policy),
            $policy,
        );
    }

    /**
     * The visitor's view of the shared repository, limited to the link's branches, History and files. It runs with the
     * link owner's token and cache, and visitors never learn which platform hosts the repository.
     */
    public function browse(string $publicId, #[SensitiveParameter] string $token, BrowseQuery $query): Preview|BranchChoices
    {
        $link = $this->authorize($publicId, $token);
        $provider = $link->ref->provider;
        try {
            $repositories = $this->repositories->forUser($link->ownerId, $provider);
        } catch (Failure $error) {
            if ($error->errorCode === $provider->code('not_connected')) {
                throw UnavailableLink::failure();
            }
            throw VisitorView::error($error);
        }
        // A link made on another instance of the owner's platform never reads the instance connected now.
        if ($repositories->instance() !== $link->instance) {
            throw UnavailableLink::failure();
        }
        try {
            $preview = $repositories->browse($link->ref, $query, $link->externalId, $link->access);
        } catch (Failure $error) {
            throw VisitorView::error($error);
        }
        // Rules that changed while the platform was being read win over the response computed under the older rules.
        $link->policy->assertCurrent();
        return VisitorView::preview($preview, $link->policy->version());
    }

    /** Ends the visitor session and with it every link it holds; the caller has checked its CSRF token. */
    public function logout(#[SensitiveParameter] string $token): void
    {
        $this->connection->get()->prepare("DELETE FROM sessions WHERE token_digest = ? AND kind = 'visitor'")->execute([hash('sha256', $token, true)]);
    }
}
