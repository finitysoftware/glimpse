<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Audit\AuditLog;
use Glimpse\Auth\Throttle;
use Glimpse\Auth\UserContext;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Connections\ConnectionService;
use Glimpse\Connections\ConnectionStatus;
use Glimpse\Connections\SavedConnection;
use Glimpse\Database\Connection;
use Glimpse\Failure;
use Glimpse\Settings;
use Glimpse\Source\Lease;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\Transport;
use RuntimeException;
use SensitiveParameter;

/**
 * A user's GitLab connection: an instance URL and a token that are verified and saved together. The token is encrypted
 * with the URL in its authenticated data and is only ever sent to that URL; a new URL always needs a token again.
 */
final readonly class GitLabConnectionService extends ConnectionService
{
    protected const COLUMNS = ['gitlab_username', 'token_expires_at', 'token_scopes'];

    /** Scopes that allow reading the API; read_api is the recommended one. */
    private const READ_SCOPES = ['read_api', 'api'];

    public function __construct(Connection $connection, Settings $settings, private GitLabTransports $transports, AuditLog $audit, Throttle $throttle, PreviewCaches $caches)
    {
        parent::__construct($connection, $settings, Provider::GitLab, $audit, $throttle, $caches);
        // The address offered for new connections must itself be a valid GitLab address that the policy allows.
        try {
            GitLabUrl::parse($settings->gitlabDefaultUrl, $this->policy());
        } catch (Failure) {
            throw new RuntimeException('Invalid server configuration.');
        }
    }

    public function policy(): GitLabNetworkPolicy
    {
        return new GitLabNetworkPolicy($this->settings->gitlabAllowedHosts, $this->settings->gitlabAllowPrivateAddresses);
    }

    public function access(int $userId): SourceAccess
    {
        $saved = $this->saved($userId) ?? throw $this->notConnected();
        $url = GitLabUrl::saved((string) $saved->instance, $this->policy());
        return new SourceAccess(Provider::GitLab, $userId, $saved->version, $this->transports->forConnection($url, $this->decrypt($userId, $saved)), $url->base, new GitLabCachePolicy());
    }

    /** The saved instance address, without decrypting the token; NULL without a connection. */
    public function instance(int $userId): ?string
    {
        return $this->saved($userId)?->instance;
    }

    /**
     * Verifies the URL and candidate token first; a rejected, restricted, unreachable or under-scoped candidate never
     * replaces the saved connection.
     */
    public function save(UserContext $user, string $baseUrl, #[SensitiveParameter] string $token, int $expectedVersion, string $address): ConnectionStatus
    {
        return $this->store($user->userId, $baseUrl, $token, $expectedVersion, $address, true);
    }

    /**
     * Admin panel: the same verified replacement for any existing account (also a disabled one), without an audit event.
     */
    public function replaceFor(int $userId, string $baseUrl, #[SensitiveParameter] string $token, int $expectedVersion, string $address): ConnectionStatus
    {
        return $this->store($userId, $baseUrl, $token, $expectedVersion, $address, false);
    }

    /**
     * Admin panel: the saved token in plain text with its instance, or NULL without a connection.
     *
     * @return array{token: string, baseUrl: string}|null
     */
    public function reveal(int $userId): ?array
    {
        $saved = $this->saved($userId);
        return $saved === null ? null : ['token' => $this->decrypt($userId, $saved), 'baseUrl' => (string) $saved->instance];
    }

    /** Identity, token details and the first catalogue page. A token without read access to the API is refused before saving. */
    protected function verify(int $userId, int $credentialVersion, Transport $transport): array
    {
        return (new Lease($this->connection->get(), $userId, $credentialVersion, Provider::GitLab))->run('gitlab-verify', static function () use ($transport): array {
            $client = new GitLabClient($transport);
            $identity = $client->user();
            $token = $client->tokenInfo();
            if ($token !== null && !$token['active']) {
                throw Failure::upstream('gitlab_credentials_rejected', 'GitLab credentials were rejected.');
            }
            if ($token !== null && array_intersect(self::READ_SCOPES, $token['scopes']) === []) {
                throw Failure::rejected('gitlab_token_scope', 'This token cannot read the GitLab API. Create a token with the read_api scope.');
            }
            $client->projects(1);
            return ['gitlab_user_id' => $identity['id'], 'gitlab_username' => $identity['username'], 'token_expires_at' => $token['expiresAt'] ?? null,
                'token_scopes' => $token === null ? null : implode(' ', $token['scopes'])];
        });
    }

    protected function describe(?SavedConnection $saved, #[SensitiveParameter] ?string $token): GitLabConnectionStatus
    {
        $scopes = $saved->account['token_scopes'] ?? null;
        $username = $saved->account['gitlab_username'] ?? null;
        $expires = $saved->account['token_expires_at'] ?? null;
        return new GitLabConnectionStatus(
            $saved !== null,
            $saved->status ?? 'not_configured',
            $saved?->instance,
            $this->settings->gitlabDefaultUrl,
            $token === null ? null : self::mask($token),
            $saved->version ?? 0,
            $username === null ? null : (string) $username,
            $expires === null ? null : (string) $expires,
            $scopes === null ? null : explode(' ', (string) $scopes),
            $saved?->checkedAt,
            $saved?->errorCode,
        );
    }

    private function store(int $userId, string $baseUrl, #[SensitiveParameter] string $token, int $expectedVersion, string $address, bool $owner): ConnectionStatus
    {
        $this->beginSave($userId, $address, $owner);
        $url = GitLabUrl::parse($baseUrl, $this->policy());
        $this->checkCandidate($token, $userId, $expectedVersion);
        $account = $this->verifyCandidate(
            $userId,
            $expectedVersion,
            $this->transports->forConnection($url, $token),
            'Check that it is active, not expired and copied completely.',
            'Check its scopes and your access to the projects you want to share.',
        );
        $this->saveVerified($userId, $owner, $expectedVersion, $token, $account, $url->base);
        return $this->status($userId);
    }
}
