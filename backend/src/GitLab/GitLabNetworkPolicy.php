<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Closure;
use Glimpse\Failure;

/** Which GitLab instances this server may contact. Every address a host resolves to must be public, and requests are pinned
 * to the checked addresses, so a user-supplied URL can never reach loopback, private, link-local or other internal networks.
 */
final readonly class GitLabNetworkPolicy
{
    /** Ranges that PHP's global-range filter still accepts: multicast and NAT64 prefixes that embed IPv4 addresses. */
    private const DENIED = ['224.0.0.0/4', 'ff00::/8', '64:ff9b::/96', '64:ff9b:1::/48'];

    /** @var list<string> */
    public array $allowedHosts;

    /**
     * @param list<string> $allowedHosts `host` or `host:port`; empty allows every public HTTPS host on port 443.
     * @param bool $allowPrivateAddresses Development and test only (e.g. a local GitLab); refused in production by Settings.
     * @param (Closure(string): list<string>)|null $resolver Returns the IP addresses of a host; the system resolver by default.
     */
    public function __construct(array $allowedHosts = [], public bool $allowPrivateAddresses = false, private ?Closure $resolver = null)
    {
        $this->allowedHosts = array_map('strtolower', $allowedHosts);
    }

    public function allows(string $host, int $port): bool
    {
        if ($this->allowedHosts === []) {
            return $port === 443;
        }
        return in_array($host . ':' . $port, $this->allowedHosts, true) || ($port === 443 && in_array($host, $this->allowedHosts, true));
    }

    /** The checked addresses of the instance, for pinning every request to them.
     * @return non-empty-list<string>
     */
    public function addresses(GitLabUrl $url): array
    {
        $addresses = array_values(array_unique(($this->resolver ?? self::resolve(...))($url->host)));
        if ($addresses === []) {
            throw Failure::rejected('gitlab_url_unresolved', 'The GitLab address could not be found. Check the URL of your GitLab instance.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || (!$this->allowPrivateAddresses && !self::publicAddress($address))) {
                throw GitLabUrl::notAllowed();
            }
        }
        return $addresses;
    }

    public static function publicAddress(string $address): bool
    {
        $binary = inet_pton($address);
        if ($binary === false || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        foreach (self::DENIED as $range) {
            [$network, $bits] = explode('/', $range);
            $prefix = inet_pton($network);
            if ($prefix === false || strlen($prefix) !== strlen($binary)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($binary, 0, $bytes) !== substr($prefix, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($binary[$bytes]) ^ ord($prefix[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['ip'] ?? null)) {
                $addresses[] = $record['ip'];
            }
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }
        if ($addresses === []) {
            // Some hosts disable dns_get_record; gethostbynamel still returns the IPv4 addresses.
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $addresses = $fallback;
            }
        }
        return $addresses;
    }
}
