<?php

declare(strict_types=1);

namespace Glimpse;

use RuntimeException;

final readonly class Settings
{
    /** The GitLab address offered for new connections unless the server configures another one. */
    public const GITLAB_DEFAULT_URL = 'https://gitlab.com';

    /** Canonical browser origin: lowercase host, optional non-default port, no path. */
    private const ORIGIN = '#^(https?)://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::([0-9]{1,5}))?$#D';

    /** @var list<string> Addresses that serve the workspace and visitor links of the same application and database. */
    public array $workspaceOrigins;
    /** Address used in new and revealed share URLs. */
    public string $sharePublicOrigin;

    /**
     * @param array{dsn: string, username: string, password: string} $database
     * @param list<string> $workspaceOrigins Empty means only $origin.
     * @param list<string> $shareAliasOrigins Old share hosts that open visitor links only, never the workspace.
     * @param list<string> $maintenanceAllowIps Client addresses that may use the API during maintenance for acceptance checks.
     * @param string $gitlabDefaultUrl GitLab address suggested in the Connections dialog.
     * @param list<string> $gitlabAllowedHosts GitLab hosts (`host` or `host:port`) users may connect to; empty allows any public HTTPS host.
     * @param bool $gitlabAllowPrivateAddresses Development and test only: lets a GitLab URL resolve to a private or local address.
     */
    public function __construct(
        public string $environment = 'production',
        public string $origin = 'https://app.glimpse.example',
        public array $database = ['dsn' => '', 'username' => '', 'password' => ''],
        public string $shareEncryptionKey = '',
        public string $githubTokenKey = '',
        public string $githubTokenKeyId = '',
        public int $cacheBudgetBytes = 268435456,
        array $workspaceOrigins = [],
        string $sharePublicOrigin = '',
        public array $shareAliasOrigins = [],
        public bool $maintenance = false,
        public array $maintenanceAllowIps = [],
        public string $gitlabDefaultUrl = self::GITLAB_DEFAULT_URL,
        public array $gitlabAllowedHosts = [],
        public bool $gitlabAllowPrivateAddresses = false,
    ) {
        $this->workspaceOrigins = $workspaceOrigins === [] ? [$origin] : $workspaceOrigins;
        $this->sharePublicOrigin = $sharePublicOrigin === '' ? $origin : $sharePublicOrigin;
        $all = [...$this->workspaceOrigins, ...$shareAliasOrigins];
        $authorities = array_map(self::authority(...), $all);
        if (!in_array($environment, ['production', 'development', 'test'], true)
            || in_array(null, $authorities, true)
            || self::authority($origin) === null
            // One address belongs to exactly one set, so a Host header always identifies a single origin.
            || count(array_unique($authorities)) !== count($all)
            || !in_array($origin, $this->workspaceOrigins, true)
            || !in_array($this->sharePublicOrigin, [...$this->workspaceOrigins, ...$shareAliasOrigins], true)
            || ($environment === 'production' && array_filter([$origin, ...$all], static fn(string $value): bool => !str_starts_with($value, 'https://')) !== [])
            || array_filter($maintenanceAllowIps, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) === false) !== []
            || $cacheBudgetBytes < 1048576
            || ($environment === 'production' && $gitlabAllowPrivateAddresses)
            || array_filter($gitlabAllowedHosts, static fn(string $host): bool => preg_match('/^[a-z0-9.-]{1,253}(?::[1-9][0-9]{0,4})?$/Di', $host) !== 1) !== []) {
            throw new RuntimeException('Invalid server configuration.');
        }
    }

    public static function load(string $configDirectory): self
    {
        $path = $configDirectory . '/settings.local.php';
        $local = is_file($path) ? require $path : [];
        if (!is_array($local)) {
            throw new RuntimeException('Invalid server configuration.');
        }
        $value = static function (string $name, mixed $fallback): string {
            $environment = getenv($name);
            return $environment !== false ? $environment : (is_string($fallback) ? $fallback : '');
        };
        // Lists come from a comma-separated environment variable or a PHP array in the private settings file.
        $list = static function (string $name, mixed $fallback): array {
            $environment = getenv($name);
            $items = $environment !== false ? explode(',', $environment) : (is_array($fallback) ? $fallback : []);
            return array_values(array_filter(
                array_map(static fn(mixed $item): string => is_string($item) ? trim($item) : '', $items),
                static fn(string $item): bool => $item !== '',
            ));
        };
        $db = is_array($local['database'] ?? null) ? $local['database'] : [];
        $key = $local['share_encryption_key'] ?? '';
        $keyPath = $configDirectory . '/share-key.local.php';
        if ($key === '' && getenv('GLIMPSE_SHARE_ENCRYPTION_KEY') === false && is_file($keyPath)) {
            $key = require $keyPath;
        }
        // The PAT key is separate from the share key; its file returns ['id' => ..., 'key' => ...].
        $tokenKey = $local['github_token_key'] ?? '';
        $tokenKeyId = $local['github_token_key_id'] ?? '';
        $tokenKeyPath = $configDirectory . '/github-token-key.local.php';
        if ($tokenKey === '' && getenv('GLIMPSE_GITHUB_TOKEN_KEY') === false && is_file($tokenKeyPath)) {
            $file = require $tokenKeyPath;
            $tokenKey = is_array($file) ? ($file['key'] ?? '') : '';
            $tokenKeyId = is_array($file) ? ($file['id'] ?? '') : '';
        }
        $workspaceOrigins = $list('GLIMPSE_WORKSPACE_ORIGINS', $local['workspace_origins'] ?? []);
        return new self(
            $value('GLIMPSE_ENV', $local['environment'] ?? 'production'),
            $value('GLIMPSE_ORIGIN', $local['origin'] ?? ($workspaceOrigins[0] ?? 'https://app.glimpse.example')),
            [
                'dsn' => $value('GLIMPSE_DB_DSN', $db['dsn'] ?? ''),
                'username' => $value('GLIMPSE_DB_USER', $db['username'] ?? ''),
                'password' => $value('GLIMPSE_DB_PASSWORD', $db['password'] ?? ''),
            ],
            $value('GLIMPSE_SHARE_ENCRYPTION_KEY', $key),
            $value('GLIMPSE_GITHUB_TOKEN_KEY', $tokenKey),
            $value('GLIMPSE_GITHUB_TOKEN_KEY_ID', $tokenKeyId),
            // Shared byte budget of all users' preview caches; a full budget skips caching instead of evicting other users.
            (int) $value('GLIMPSE_CACHE_GLOBAL_MAX_BYTES', (string) ($local['cache_global_max_bytes'] ?? 268435456)),
            $workspaceOrigins,
            $value('GLIMPSE_SHARE_PUBLIC_ORIGIN', $local['share_public_origin'] ?? ''),
            $list('GLIMPSE_SHARE_ALIAS_ORIGINS', $local['share_alias_origins'] ?? []),
            // bin/maintenance-mode.php creates the flag file; the API then answers 503 until it is removed.
            $value('GLIMPSE_MAINTENANCE', '') === '1' || is_file($configDirectory . '/maintenance.flag'),
            $list('GLIMPSE_MAINTENANCE_ALLOW_IPS', $local['maintenance_allow_ips'] ?? []),
            $value('GLIMPSE_GITLAB_DEFAULT_URL', $local['gitlab_default_url'] ?? self::GITLAB_DEFAULT_URL),
            $list('GLIMPSE_GITLAB_ALLOWED_HOSTS', $local['gitlab_allowed_hosts'] ?? []),
            $value('GLIMPSE_GITLAB_ALLOW_PRIVATE_ADDRESSES', ($local['gitlab_allow_private_addresses'] ?? false) === true ? '1' : '') === '1',
        );
    }

    public function secureCookies(): bool
    {
        return str_starts_with($this->origin, 'https://');
    }

    /** Configured workspace or share alias origin whose address matches a Host header, or null for any other host. */
    public function originForHost(string $host): ?string
    {
        $host = strtolower($host);
        foreach ([...$this->workspaceOrigins, ...$this->shareAliasOrigins] as $origin) {
            $default = str_starts_with($origin, 'https://') ? ':443' : ':80';
            if (self::authority($origin) === (str_ends_with($host, $default) ? substr($host, 0, -strlen($default)) : $host)) {
                return $origin;
            }
        }
        return null;
    }

    public function isShareAlias(string $origin): bool
    {
        return in_array($origin, $this->shareAliasOrigins, true);
    }

    /** host[:port] of a canonical origin, or null when browsers would never send it in this exact form. */
    private static function authority(string $origin): ?string
    {
        if (preg_match(self::ORIGIN, $origin, $match) !== 1) {
            return null;
        }
        $port = $match[3] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535 || $port !== (string) (int) $port
            || $port === ($match[1] === 'https' ? '443' : '80'))) {
            return null;
        }
        return $match[2] . ($port !== '' ? ':' . $port : '');
    }
}
