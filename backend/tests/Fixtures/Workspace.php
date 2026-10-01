<?php

declare(strict_types=1);

namespace Glimpse\Tests\Fixtures;

use Glimpse\Database\Connection;
use Glimpse\Database\Migrator;
use Glimpse\GitHub\GitHubTransports;
use Glimpse\GitLab\GitLabTransports;
use Glimpse\Services;
use Glimpse\Settings;
use PDO;

/** Test-database helpers for signed-in workspace requests. */
final class Workspace
{
    public const PASSWORD = 'workspace test password';

    /** Removes application rows, including accounts, from the dedicated test database. Link policies and visibility profiles cascade.
     * A fresh database is reset while it is still at schema 005, so tables of later migrations may not exist yet.
     */
    /** The services of the API on this database connection (by default a new one), with test transports where given. */
    public static function services(Settings $settings, ?PDO $pdo = null, ?GitHubTransports $github = null, ?GitLabTransports $gitlab = null): Services
    {
        return new Services($settings, $github, $gitlab, new Connection($settings, $pdo));
    }

    public static function reset(PDO $pdo): void
    {
        $read = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
        $existing = $read === false ? [] : $read->fetchAll(PDO::FETCH_COLUMN);
        foreach (['session_shares', 'share_links', 'api_cache', 'api_cache_state', 'api_leases', 'audit_events', 'sessions',
            'auth_throttles', 'repositories', 'github_connections', 'gitlab_connections', 'user_roles', 'users'] as $table) {
            if (in_array($table, $existing, true)) {
                $pdo->exec('DELETE FROM ' . $table);
            }
        }
        $pdo->exec('UPDATE cache_capacity_state SET reserved_bytes = 0, reserved_entries = 0');
    }

    /** Brings the dedicated test database to the full schema. Rows left without an owner by an interrupted earlier run,
     * which stop 006, are removed first.
     */
    public static function migrate(Settings $settings): PDO
    {
        $connection = new Connection($settings);
        $pdo = $connection->get();
        $migrator = new Migrator($connection, dirname(__DIR__, 2) . '/migrations');
        if ($migrator->run()->blocked()) {
            self::reset($pdo);
            $migrator->run();
        }
        return $pdo;
    }

    public const TOKEN_KEY_ID = 'test-key';

    public static function tokenKey(): string
    {
        return base64_encode(str_repeat('t', 32));
    }

    public const GITLAB_URL = 'https://gitlab.example';

    /** Creates an account through the provisioning service and returns a signed-in workspace session token.
     * With $github or $gitlab the account also saves a synthetic token through the normal verify-and-save path.
     */
    public static function signIn(
        Settings $settings,
        string $username = 'owner',
        string $password = self::PASSWORD,
        ?GitHubTransports $github = null,
        ?GitLabTransports $gitlab = null,
    ): string {
        $services = new Services($settings, $github, $gitlab);
        $services->accounts->create($username, $password);
        $session = $services->auth->login($username, $password, 'fixture');
        if ($github !== null) {
            $services->github->save($session->context, self::token($username), 0, 'fixture');
        }
        if ($gitlab !== null) {
            $services->gitlab->save($session->context, self::GITLAB_URL, self::gitlabToken($username), 0, 'fixture');
        }
        return $session->token;
    }

    /** Synthetic per-user GitLab token; fixtures never contain a real credential. */
    public static function gitlabToken(string $username): string
    {
        return 'fixture_gitlab_token_for_' . $username;
    }

    /** Synthetic per-user token; fixtures never contain a real credential. */
    public static function token(string $username): string
    {
        return 'fixture_token_for_' . $username;
    }

    /** Fast account row for tests that do not sign in. */
    public static function user(PDO $pdo, string $username = 'owner'): int
    {
        $pdo->prepare("INSERT INTO users (username, password_hash, password_changed_at, created_at, updated_at)
            VALUES (?, '!', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")->execute([$username]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO user_roles (user_id, role, created_at) VALUES (?, 'member', UTC_TIMESTAMP(6))")->execute([$id]);
        return $id;
    }
}
