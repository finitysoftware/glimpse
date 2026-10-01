<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Audit\AuditLog;
use Glimpse\Auth\Throttle;
use Glimpse\Auth\UserContext;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Connections\ConnectionService;
use Glimpse\Connections\ConnectionStatus;
use Glimpse\Connections\SavedConnection;
use Glimpse\Database\Connection;
use Glimpse\Settings;
use Glimpse\Source\Lease;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\Transport;
use SensitiveParameter;

/** A user's GitHub connection: a personal access token for api.github.com. */
final readonly class GitHubConnectionService extends ConnectionService
{
    protected const COLUMNS = ['github_login'];

    public function __construct(Connection $connection, Settings $settings, private GitHubTransports $transports, AuditLog $audit, Throttle $throttle, PreviewCaches $caches)
    {
        parent::__construct($connection, $settings, Provider::GitHub, $audit, $throttle, $caches);
    }

    public function access(int $userId): SourceAccess
    {
        $saved = $this->saved($userId) ?? throw $this->notConnected();
        $transport = $this->transports->forToken($this->decrypt($userId, $saved));
        return new SourceAccess(Provider::GitHub, $userId, $saved->version, $transport, Provider::GITHUB_INSTANCE, new GitHubCachePolicy());
    }

    /**
     * Verifies the candidate first; a rejected, restricted or unreachable candidate never replaces the saved connection.
     */
    public function save(UserContext $user, #[SensitiveParameter] string $token, int $expectedVersion, string $address): ConnectionStatus
    {
        return $this->store($user->userId, $token, $expectedVersion, $address, true);
    }

    /**
     * Admin panel: the same verified replacement for any existing account (also a disabled one), without an audit event.
     */
    public function replaceFor(int $userId, #[SensitiveParameter] string $token, int $expectedVersion, string $address): ConnectionStatus
    {
        return $this->store($userId, $token, $expectedVersion, $address, false);
    }

    /** Admin panel: the saved token in plain text, or NULL without a connection. */
    public function reveal(int $userId): ?string
    {
        $saved = $this->saved($userId);
        return $saved === null ? null : $this->decrypt($userId, $saved);
    }

    /** A successful /user response with an empty catalogue is a valid token with no available repositories. */
    protected function verify(int $userId, int $credentialVersion, Transport $transport): array
    {
        $identity = (new Lease($this->connection->get(), $userId, $credentialVersion, Provider::GitHub))->run('github-verify', static function () use ($transport): array {
            $client = new GitHubClient($transport);
            $identity = $client->user();
            $client->repositories(1);
            return $identity;
        });
        return ['github_user_id' => $identity['id'], 'github_login' => $identity['login']];
    }

    protected function describe(?SavedConnection $saved, #[SensitiveParameter] ?string $token): GitHubConnectionStatus
    {
        $login = $saved->account['github_login'] ?? null;
        return new GitHubConnectionStatus(
            $saved !== null,
            $saved->status ?? 'not_configured',
            $token === null ? null : self::mask($token),
            $saved->version ?? 0,
            $login === null ? null : (string) $login,
            $saved?->checkedAt,
            $saved?->errorCode,
        );
    }

    private function store(int $userId, #[SensitiveParameter] string $token, int $expectedVersion, string $address, bool $owner): ConnectionStatus
    {
        $this->beginSave($userId, $address, $owner);
        $this->checkCandidate($token, $userId, $expectedVersion);
        $account = $this->verifyCandidate(
            $userId,
            $expectedVersion,
            $this->transports->forToken($token),
            'Check that it is active and copied completely.',
            'Check its resource owner, repository access and any organization approval.',
        );
        $this->saveVerified($userId, $owner, $expectedVersion, $token, $account, null);
        return $this->status($userId);
    }
}
