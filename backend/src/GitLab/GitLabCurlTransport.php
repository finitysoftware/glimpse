<?php

declare(strict_types=1);

namespace Glimpse\GitLab;

use Glimpse\Failure;
use Glimpse\Source\HttpResult;
use Glimpse\Source\HttpsGet;
use Glimpse\Source\Provider;
use Glimpse\Source\Transport;
use SensitiveParameter;

/** Sends the token only to the verified instance: fixed API paths, addresses checked and pinned, no redirects or proxies. */
final class GitLabCurlTransport implements Transport
{
    /** @var list<string>|null Checked on the first request, then reused by this request's later calls. */
    private ?array $addresses = null;

    public function __construct(private readonly GitLabUrl $url, #[SensitiveParameter] private readonly string $token, private readonly GitLabNetworkPolicy $policy) {}

    /** Paths relative to /api/v4 that Glimpse reads; everything else is refused before any network access. */
    public static function allowedPath(string $path): bool
    {
        if (strlen($path) > 4096 || preg_match('#^[A-Za-z0-9/?=&%._~-]+$#D', $path) !== 1 || preg_match('#(?:^|/)\.\.?(?:/|\?|$)#', $path) === 1) {
            return false;
        }
        return in_array($path, ['/user', '/personal_access_tokens/self'], true)
            || preg_match('#^/projects\?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=[1-9][0-9]{0,4}$#D', $path) === 1
            || preg_match('#^/projects/[1-9][0-9]{0,17}(?:$|/repository/(?:branches|commits|tree|files|merge_base)(?:[/?]|$))#D', $path) === 1;
    }

    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult
    {
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token)) {
            throw Failure::unavailable('gitlab_not_configured', 'GitLab access has not been configured.');
        }
        if (!self::allowedPath($path)) {
            throw Failure::invalid('invalid_gitlab_path', 'Unsupported GitLab operation.');
        }
        $addresses = $this->addresses ??= $this->policy->addresses($this->url);
        $headers = ['PRIVATE-TOKEN: ' . $this->token, 'Accept: application/json', 'User-Agent: Glimpse/0.0'];
        [$result, $connectedTo] = HttpsGet::send(Provider::GitLab, $this->url->api($path), $headers, $etag, $timeoutMilliseconds, [
            // The host always connects to the addresses that passed the network policy, never to a later DNS answer.
            CURLOPT_RESOLVE => [$this->url->host . ':' . $this->url->port . ':' . implode(',', array_map(
                static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address,
                $addresses,
            ))],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);
        if ($connectedTo !== '' && !in_array(inet_pton($connectedTo), array_map('inet_pton', $addresses), true)) {
            throw Failure::upstream('gitlab_unavailable', 'Unable to reach GitLab securely.');
        }
        return $result;
    }
}
