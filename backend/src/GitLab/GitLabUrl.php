<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Failure;

/** The normalized HTTPS address of a GitLab instance: lowercase host, optional non-default port and installation path, no trailing slash. */
final readonly class GitLabUrl
{
    public const MAX_LENGTH = 255;

    private function __construct(public string $base, public string $host, public int $port) {}

    /** Validates the shape and the allowlist only; addresses are checked when a transport is created. */
    public static function parse(string $value, GitLabNetworkPolicy $policy): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $value) === 1) {
            self::invalid();
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_starts_with(strtolower($value), 'https://')) {
            self::invalid();
        }
        $host = strtolower($parts['host']);
        // Host names only: an IP literal has no certificate name to verify and bypasses the host allowlist.
        if (strlen($host) > 253 || preg_match('/^(?=.*[a-z])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host) !== 1) {
            self::invalid();
        }
        $port = $parts['port'] ?? 443;
        $path = rtrim($parts['path'] ?? '', '/');
        // A pasted API address is accepted as the instance address.
        if (str_ends_with(strtolower($path), '/api/v4')) {
            $path = substr($path, 0, -7);
        }
        foreach ($path === '' ? [] : explode('/', substr($path, 1)) as $segment) {
            if (preg_match('/^[A-Za-z0-9._~-]{1,100}$/D', $segment) !== 1 || in_array($segment, ['.', '..'], true)) {
                self::invalid();
            }
        }
        if (!$policy->allows($host, $port)) {
            throw self::notAllowed();
        }
        $base = 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
        if (strlen($base) > self::MAX_LENGTH) {
            self::invalid();
        }
        return new self($base, $host, $port);
    }

    /** A saved address is trusted as stored (it was parsed when saved), but still passes the current allowlist. */
    public static function saved(string $base, GitLabNetworkPolicy $policy): self
    {
        $url = self::parse($base, $policy);
        if ($url->base !== $base) {
            self::invalid();
        }
        return $url;
    }

    public function api(string $path): string
    {
        return $this->base . '/api/v4' . $path;
    }

    /** Browser address of the instance page for new personal access tokens, with the name and read_api scope prefilled. */
    public function tokenPage(): string
    {
        return $this->base . '/-/user_settings/personal_access_tokens?name=Glimpse&scopes=read_api';
    }

    public static function notAllowed(): Failure
    {
        return Failure::rejected('gitlab_url_not_allowed', 'This GitLab address is not allowed on this server. Use the public HTTPS address of your GitLab instance.');
    }

    private static function invalid(): never
    {
        throw Failure::invalid('invalid_gitlab_url', 'Enter the HTTPS address of your GitLab instance, such as https://gitlab.com.');
    }
}
