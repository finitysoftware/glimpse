<?php

declare(strict_types=1);

namespace Glimpse\Connections;

use Glimpse\Audit\AuditLog;
use Glimpse\Auth\Throttle;
use Glimpse\Auth\UserAuth;
use Glimpse\Auth\UserContext;
use Glimpse\Cache\PreviewCaches;
use Glimpse\Database\Connection;
use Glimpse\Database\Dates;
use Glimpse\Database\Transaction;
use Glimpse\Failure;
use Glimpse\Settings;
use Glimpse\Source\ConnectionState;
use Glimpse\Source\Provider;
use Glimpse\Source\SourceAccess;
use Glimpse\Source\TokenCipher;
use Glimpse\Source\Transport;
use PDO;
use PDOException;
use SensitiveParameter;

/**
 * One connection per user and platform. A candidate token is verified with the platform before it replaces the saved one,
 * tokens are stored encrypted, and there is no server-wide token. GitHubConnectionService and GitLabConnectionService add
 * what differs: how a token is verified, the account columns they keep and how a connection is shown.
 */
abstract readonly class ConnectionService
{
    public const SAVE_USER_LIMIT = 10;
    public const SAVE_ADDRESS_LIMIT = 30;
    public const PROBE_USER_LIMIT = 30;

    /** The platform's own columns of its connections table, read with every saved connection. */
    protected const COLUMNS = [];

    public function __construct(
        protected Connection $connection,
        protected Settings $settings,
        protected Provider $provider,
        protected AuditLog $audit,
        protected Throttle $throttle,
        protected PreviewCaches $caches,
    ) {}

    /** The saved token as a transport. A missing connection is a 409; an unreadable token is a server error. */
    abstract public function access(int $userId): SourceAccess;

    /**
     * Checks a token with the platform and returns the account columns to save with it.
     *
     * @return array<string, int|string|null>
     */
    abstract protected function verify(int $userId, int $credentialVersion, Transport $transport): array;

    /** How Connections shows the connection: its state, the masked token and the platform account. */
    abstract protected function describe(?SavedConnection $saved, #[SensitiveParameter] ?string $token): ConnectionStatus;

    /**
     * Saved state only: no decryption and no platform request, so it is safe on every render.
     *
     * @return array{configured: bool, status: string, credentialVersion: int}
     */
    public function summary(int $userId): array
    {
        $saved = $this->saved($userId);
        return ['configured' => $saved !== null, 'status' => $saved->status ?? 'not_configured', 'credentialVersion' => $saved->version ?? 0];
    }

    public function status(int $userId): ConnectionStatus
    {
        $saved = $this->saved($userId);
        return $this->describe($saved, $saved === null ? null : $this->decrypt($userId, $saved));
    }

    /**
     * Admin panel: removes the saved token and this user's cache of the platform; the user's links of the platform stop
     * serving until a token is saved again. Returns false when there was no connection.
     */
    public function remove(int $userId): bool
    {
        return $this->delete($userId, false);
    }

    /** Workspace Disconnect: removes the owner's token and cache of the platform; repositories, links and visibility rules are kept. */
    public function disconnect(UserContext $user): ConnectionStatus
    {
        $this->delete($user->userId, true);
        return $this->status($user->userId);
    }

    /** Fresh check of the saved token. Only a 401 marks the credentials rejected; quota limits never do. */
    public function probe(UserContext $user): ConnectionStatus
    {
        $provider = $this->provider;
        $this->throttle->limit($provider->value . ':probe:user', (string) $user->userId, self::PROBE_USER_LIMIT);
        $access = $this->access($user->userId);
        $account = [];
        $error = null;
        try {
            $account = $this->verify($user->userId, $access->credentialVersion, $access->transport);
            $status = 'connected';
        } catch (Failure $failure) {
            if (in_array($failure->errorCode, [$provider->code('rate_limited'), $provider->code('busy')], true)) {
                throw $failure;
            }
            $status = match ($failure->errorCode) {
                $provider->code('credentials_rejected') => 'credentials_rejected',
                $provider->code('access_denied'), $provider->code('token_scope') => 'access_restricted',
                default => 'temporarily_unavailable',
            };
            $error = $failure->errorCode;
        }
        $pdo = $this->connection->get();
        Transaction::run($pdo, function () use ($pdo, $provider, $status, $error, $account, $user, $access): void {
            // Compare-and-set: a probe of an older token never changes the state of a newer one. A failed check keeps the account columns.
            $pdo->prepare('UPDATE ' . $provider->connectionTable() . ' SET last_check_status = ?, last_error_code = ?, last_checked_at = UTC_TIMESTAMP(6), '
                . self::assignments($account) . 'updated_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND credential_version = ?')
                ->execute([$status, $error, ...array_values($account), $user->userId, $access->credentialVersion]);
            if ($status === 'credentials_rejected') {
                $this->caches->invalidateUser($user->userId, $provider);
            }
            $this->audit->record(
                $provider->value . '_connection_probed',
                'user',
                $user->userId,
                $user->userId,
                ['status' => $status, 'credentialVersion' => $access->credentialVersion],
                $status === 'connected' ? 'success' : 'failure',
                $error,
                UserAuth::AUDIENCE,
            );
        });
        return $this->status($user->userId);
    }

    /** First half visible, the rest as asterisks; produced on the server and sent only to the owner. */
    public static function mask(#[SensitiveParameter] string $token): string
    {
        $visible = intdiv(strlen($token), 2);
        return substr($token, 0, $visible) . str_repeat('*', strlen($token) - $visible);
    }

    /** First step of saving a token: the account must exist (admin panel), and saving is throttled per user and address. */
    protected function beginSave(int $userId, string $address, bool $owner): void
    {
        if (!$owner) {
            $account = $this->connection->get()->prepare('SELECT 1 FROM users WHERE id = ?');
            $account->execute([$userId]);
            if ($account->fetchColumn() === false) {
                throw Failure::notFound('user_not_found', 'This account no longer exists.');
            }
        }
        $this->throttle->limit($this->provider->value . ':save:user', (string) $userId, self::SAVE_USER_LIMIT);
        $this->throttle->limit($this->provider->value . ':save:address', $address, self::SAVE_ADDRESS_LIMIT);
    }

    /** Before the platform is asked: the token's format, the server's token storage and the version the owner saw. */
    protected function checkCandidate(#[SensitiveParameter] string $token, int $userId, int $expectedVersion): void
    {
        $label = $this->provider->label();
        if (!TokenCipher::validToken($token)) {
            throw Failure::rejected($this->provider->code('token_invalid_format'), 'Enter a ' . $label . ' token of at most 1024 printable characters without spaces.');
        }
        if (!$this->cipher()->configured()) {
            throw Failure::unavailable($this->provider->code('token_storage_unconfigured'), $label . ' token storage has not been configured on the server.');
        }
        if (($this->saved($userId)->version ?? 0) !== $expectedVersion) {
            $this->changed();
        }
    }

    /**
     * Checks the candidate with the platform, without any SQL lock and without the saved token's cache. A rejected or
     * restricted token is the owner's input error (422) with $rejectedHint or $restrictedHint.
     *
     * @return array<string, int|string|null> The account columns to save.
     */
    protected function verifyCandidate(int $userId, int $expectedVersion, Transport $candidate, string $rejectedHint, string $restrictedHint): array
    {
        try {
            return $this->verify($userId, $expectedVersion, $candidate);
        } catch (Failure $error) {
            $label = $this->provider->label();
            throw match ($error->errorCode) {
                $this->provider->code('credentials_rejected') => Failure::rejected($this->provider->code('token_rejected'), $label . ' rejected this token. ' . $rejectedHint),
                $this->provider->code('access_denied') => Failure::rejected($this->provider->code('token_restricted'), $label . ' restricted this token. ' . $restrictedHint),
                default => $error,
            };
        }
    }

    /**
     * Saves a verified token as the next credential generation, with its account columns and (GitLab) its instance.
     *
     * @param array<string, int|string|null> $account
     */
    protected function saveVerified(int $userId, bool $owner, int $expectedVersion, #[SensitiveParameter] string $token, array $account, ?string $instance): void
    {
        $provider = $this->provider;
        $table = $provider->connectionTable();
        $cipher = $this->cipher();
        $pdo = $this->connection->get();
        try {
            Transaction::run($pdo, function () use ($pdo, $provider, $table, $cipher, $userId, $owner, $expectedVersion, $token, $account, $instance): void {
                $user = $pdo->prepare('SELECT status FROM users WHERE id = ? FOR UPDATE');
                $user->execute([$userId]);
                $status = $user->fetchColumn();
                // The owner must still be signed in to an active account; an administrator may also set a disabled account's token.
                if ($owner && $status !== 'active') {
                    throw Failure::unauthenticated('unauthorized', 'Sign in to continue.');
                }
                if ($status === false) {
                    throw Failure::notFound('user_not_found', 'This account no longer exists.');
                }
                $read = $pdo->prepare('SELECT id, credential_version' . ($instance === null ? '' : ', base_url') . ' FROM ' . $table . ' WHERE user_id = ? FOR UPDATE');
                $read->execute([$userId]);
                $current = $read->fetch(PDO::FETCH_ASSOC);
                // Another tab may have saved a newer token while this candidate was being checked.
                if ((is_array($current) ? (int) $current['credential_version'] : 0) !== $expectedVersion) {
                    $this->changed();
                }
                $instanceColumn = $instance === null ? [] : ['base_url' => $instance];
                if (is_array($current)) {
                    $connectionId = (int) $current['id'];
                    $version = (int) $current['credential_version'] + 1;
                } else {
                    // Credential generations keep increasing after a removed connection, so no cache generation is ever reused.
                    $version = $this->caches->generation($userId, $provider) + 1;
                    $columns = ['user_id' => $userId] + $instanceColumn + ['encrypted_token' => '', 'encryption_key_id' => $cipher->keyId, 'credential_version' => $version];
                    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($columns)) . ', created_at, updated_at) VALUES ('
                        . str_repeat('?, ', count($columns)) . 'UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')
                        ->execute(array_values($columns));
                    $connectionId = (int) $pdo->lastInsertId();
                }
                $saved = $instanceColumn + [
                    'encrypted_token' => $cipher->encrypt($userId, $connectionId, $token, $instance ?? ''),
                    'encryption_key_id' => $cipher->keyId,
                    'credential_version' => $version,
                ] + $account;
                $pdo->prepare('UPDATE ' . $table . ' SET ' . self::assignments($saved) . "last_check_status = 'connected', last_error_code = NULL,
                    last_checked_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE id = ?")
                    ->execute([...array_values($saved), $connectionId]);
                // A new credential generation: only this user's entries of the platform, counters and last known quota are discarded.
                $this->caches->resetUser($userId, $provider, $version);
                if ($owner) {
                    $this->audit->record(
                        $provider->value . (is_array($current) ? '_token_replaced' : '_token_configured'),
                        'user',
                        $userId,
                        $userId,
                        ['credentialVersion' => $version]
                            + ($instance === null ? [] : ['instanceChanged' => is_array($current) && $current['base_url'] !== $instance]),
                        sessionAudience: UserAuth::AUDIENCE,
                    );
                }
            });
        } catch (PDOException $error) {
            // Another window created the connection at the same moment.
            if (($error->errorInfo[1] ?? null) === 1062) {
                $this->changed();
            }
            throw $error;
        }
    }

    protected function saved(int $userId): ?SavedConnection
    {
        // GitLab connections also keep the instance their token belongs to.
        $instance = $this->provider === Provider::GitLab ? 'base_url, ' : '';
        $columns = static::COLUMNS === [] ? '' : ', ' . implode(', ', static::COLUMNS);
        $read = $this->connection->get()->prepare('SELECT id, ' . $instance . 'encrypted_token, encryption_key_id, credential_version, last_check_status,
            last_error_code, last_checked_at' . $columns . ' FROM ' . $this->provider->connectionTable() . ' WHERE user_id = ?');
        $read->execute([$userId]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $text = fn(mixed $value): ?string => $value === null ? null : (string) $value;
        $account = [];
        foreach (static::COLUMNS as $column) {
            $account[$column] = $text($row[$column]);
        }
        return new SavedConnection(
            (int) $row['id'],
            (string) $row['encrypted_token'],
            (string) $row['encryption_key_id'],
            (int) $row['credential_version'],
            (string) $row['last_check_status'],
            $text($row['last_error_code']),
            Dates::isoOrNull($row['last_checked_at']),
            $text($row['base_url'] ?? null),
            $account,
        );
    }

    protected function decrypt(int $userId, SavedConnection $saved): string
    {
        return $this->cipher()->decrypt($userId, $saved->id, $saved->keyId, $saved->encryptedToken, $saved->instance ?? '');
    }

    protected function notConnected(): Failure
    {
        return ConnectionState::notConnected($this->provider);
    }

    private function cipher(): TokenCipher
    {
        return new TokenCipher($this->settings->githubTokenKey, $this->settings->githubTokenKeyId, $this->provider);
    }

    private function delete(int $userId, bool $owner): bool
    {
        $pdo = $this->connection->get();
        $provider = $this->provider;
        $removed = Transaction::run($pdo, function () use ($pdo, $provider, $userId, $owner): bool {
            $delete = $pdo->prepare('DELETE FROM ' . $provider->connectionTable() . ' WHERE user_id = ?');
            $delete->execute([$userId]);
            $removed = $delete->rowCount() === 1;
            // Only the owner's own Disconnect is audited; the admin panel records nothing.
            if ($owner && $removed) {
                $this->audit->record($provider->value . '_token_removed', 'user', $userId, $userId, [], sessionAudience: UserAuth::AUDIENCE);
            }
            return $removed;
        });
        // A new cache revision also stops requests still running with the removed token from writing entries.
        $this->caches->invalidateUser($userId, $provider);
        return $removed;
    }

    private function changed(): never
    {
        throw Failure::conflict('connection_changed', 'The ' . $this->provider->label() . ' connection was changed in another window. Reload it before saving again.');
    }

    /**
     * "column = ?, " for each column, to write a row whose column names come from this class, never from a request.
     *
     * @param array<string, mixed> $values
     */
    private static function assignments(array $values): string
    {
        return implode('', array_map(fn(string $column): string => $column . ' = ?, ', array_keys($values)));
    }
}
