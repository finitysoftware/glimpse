<?php

declare(strict_types=1);

namespace Glimpse\GitHub;

use Glimpse\Failure;
use Glimpse\Source\HttpResult;
use Glimpse\Source\HttpsGet;
use Glimpse\Source\Provider;
use Glimpse\Source\Transport;
use SensitiveParameter;

/** Sends the token only to api.github.com, and only for the paths Glimpse reads. */
final readonly class CurlTransport implements Transport
{
    public function __construct(#[SensitiveParameter] private string $token) {}

    public function get(string $path, int $timeoutMilliseconds, ?string $etag = null): HttpResult
    {
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token)) {
            throw Failure::unavailable('github_not_configured', 'GitHub access has not been configured.');
        }
        if ((!str_starts_with($path, '/repos/') && $path !== '/user'
            && !preg_match('#^/user/repos\?per_page=30&page=[1-9][0-9]*&sort=full_name&direction=asc$#D', $path))
            || preg_match('/[\r\n#]/', $path)) {
            throw Failure::invalid('invalid_github_path', 'Unsupported GitHub operation.');
        }
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: Glimpse/0.0'];
        [$result] = HttpsGet::send(Provider::GitHub, 'https://api.github.com' . $path, $headers, $etag, $timeoutMilliseconds);
        return $result;
    }
}
