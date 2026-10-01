<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\FailureKind;
use Glimpse\GitLab\GitLabCurlTransport;
use Glimpse\GitLab\GitLabNetworkPolicy;
use Glimpse\GitLab\GitLabUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitLabUrlTest extends TestCase
{
    public function testValidAddressesAreNormalized(): void
    {
        $policy = new GitLabNetworkPolicy();
        foreach ([
            'https://gitlab.com' => 'https://gitlab.com',
            '  HTTPS://GitLab.Example.COM/  ' => 'https://gitlab.example.com',
            'https://code.example.org/gitlab/' => 'https://code.example.org/gitlab',
            'https://gitlab.com/api/v4' => 'https://gitlab.com',
            'https://gitlab.com:443/group-tools/git.lab' => 'https://gitlab.com/group-tools/git.lab',
            'https://xn--gtlab-9za.example' => 'https://xn--gtlab-9za.example',
        ] as $input => $expected) {
            $url = GitLabUrl::parse($input, $policy);
            self::assertSame($expected, $url->base, $input);
            self::assertSame(443, $url->port);
        }
        $url = GitLabUrl::parse('https://code.example.org/gitlab', $policy);
        self::assertSame('https://code.example.org/gitlab/api/v4/projects/42', $url->api('/projects/42'));
        self::assertSame('https://code.example.org/gitlab/-/user_settings/personal_access_tokens?name=Glimpse&scopes=read_api', $url->tokenPage());
        self::assertSame('code.example.org', $url->host);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'plain http' => ['http://gitlab.com'];
        yield 'no scheme' => ['gitlab.com'];
        yield 'other scheme' => ['ftp://gitlab.com'];
        yield 'credentials' => ['https://user:secret@gitlab.com'];
        yield 'user only' => ['https://token@gitlab.com'];
        yield 'query' => ['https://gitlab.com/?next=/admin'];
        yield 'fragment' => ['https://gitlab.com/#top'];
        yield 'IPv4 literal' => ['https://10.0.0.5'];
        yield 'public IPv4 literal' => ['https://8.8.8.8'];
        yield 'IPv6 literal' => ['https://[::1]'];
        yield 'single label' => ['https://localhost'];
        yield 'dot segment' => ['https://gitlab.com/a/../b'];
        yield 'encoded path' => ['https://gitlab.com/%2e%2e'];
        yield 'backslash' => ['https://gitlab.com\\@evil.example'];
        yield 'space' => ['https://git lab.com'];
        yield 'unicode host' => ['https://gitlab.čom'];
        yield 'empty' => [''];
        yield 'too long' => ['https://gitlab.com/' . str_repeat('a', 250)];
    }

    #[DataProvider('invalidAddresses')]
    public function testMalformedAddressesAreRejectedWithoutNetworkAccess(string $input): void
    {
        $resolved = false;
        $policy = new GitLabNetworkPolicy(resolver: static function () use (&$resolved): array {
            $resolved = true;
            return ['8.8.8.8'];
        });
        try {
            GitLabUrl::parse($input, $policy);
            self::fail('Accepted: ' . $input);
        } catch (Failure $error) {
            self::assertSame([FailureKind::Invalid, 'invalid_gitlab_url'], [$error->kind, $error->errorCode]);
        }
        self::assertFalse($resolved);
    }

    public function testPortsAndHostsFollowTheAllowlist(): void
    {
        $open = new GitLabNetworkPolicy();
        foreach (['https://gitlab.example:8443', 'https://gitlab.example:22'] as $input) {
            try {
                GitLabUrl::parse($input, $open);
                self::fail('Non-default port accepted without an allowlist entry.');
            } catch (Failure $error) {
                self::assertSame([FailureKind::Rejected, 'gitlab_url_not_allowed'], [$error->kind, $error->errorCode]);
            }
        }
        $listed = new GitLabNetworkPolicy(['gitlab.com', 'Code.Example.org:8443']);
        self::assertSame('https://code.example.org:8443', GitLabUrl::parse('https://code.example.org:8443/', $listed)->base);
        self::assertSame(8443, GitLabUrl::parse('https://code.example.org:8443', $listed)->port);
        self::assertSame('https://gitlab.com', GitLabUrl::parse('https://gitlab.com', $listed)->base);
        foreach (['https://code.example.org', 'https://gitlab.example', 'https://gitlab.com:8443'] as $input) {
            try {
                GitLabUrl::parse($input, $listed);
                self::fail('Host outside the allowlist accepted: ' . $input);
            } catch (Failure $error) {
                self::assertSame('gitlab_url_not_allowed', $error->errorCode);
            }
        }
        self::assertSame('https://gitlab.com', GitLabUrl::saved('https://gitlab.com', $listed)->base);
        try {
            GitLabUrl::saved('https://GitLab.com/', $listed);
            self::fail('A non-normalized saved address was accepted.');
        } catch (Failure $error) {
            self::assertSame('invalid_gitlab_url', $error->errorCode);
        }
    }

    public function testEveryResolvedAddressMustBePublic(): void
    {
        $url = static fn(GitLabNetworkPolicy $policy): GitLabUrl => GitLabUrl::parse('https://gitlab.example', $policy);
        $resolving = static fn(array $addresses, bool $private = false): GitLabNetworkPolicy => new GitLabNetworkPolicy([], $private, static fn(string $host): array => array_values(array_map('strval', $addresses)));
        self::assertSame(['203.0.114.10', '2606:4700::1111'], $resolving(['203.0.114.10', '2606:4700::1111'])->addresses($url(new GitLabNetworkPolicy())));
        foreach ([['127.0.0.1'], ['10.1.2.3'], ['172.16.0.1'], ['192.168.1.1'], ['169.254.169.254'], ['100.64.0.1'], ['0.0.0.0'], ['224.0.0.1'],
            ['255.255.255.255'], ['::1'], ['fc00::1'], ['fe80::1'], ['::ffff:10.0.0.1'], ['64:ff9b::a00:1'], ['ff02::1'], ['2001:db8::1'],
            ['8.8.8.8', '10.0.0.1'], ['not-an-address']] as $addresses) {
            try {
                $resolving($addresses)->addresses($url(new GitLabNetworkPolicy()));
                self::fail('Internal address accepted: ' . implode(',', $addresses));
            } catch (Failure $error) {
                self::assertSame([FailureKind::Rejected, 'gitlab_url_not_allowed'], [$error->kind, $error->errorCode]);
            }
        }
        try {
            $resolving([])->addresses($url(new GitLabNetworkPolicy()));
            self::fail('Unresolved host accepted.');
        } catch (Failure $error) {
            self::assertSame('gitlab_url_unresolved', $error->errorCode);
        }
        // Development and tests may reach a local instance explicitly.
        self::assertSame(['127.0.0.1'], $resolving(['127.0.0.1'], true)->addresses($url(new GitLabNetworkPolicy())));
    }

    public function testTransportOnlySendsTheTokenToKnownApiPaths(): void
    {
        foreach (['/user', '/personal_access_tokens/self', '/projects?membership=true&min_access_level=20&order_by=path&sort=asc&per_page=30&page=2',
            '/projects/42', '/projects/42/repository/tree?path=docs&ref=' . str_repeat('a', 40) . '&per_page=100&page=1',
            '/projects/42/repository/files/docs%2F%C4%8Ditaj%20me.txt?ref=' . str_repeat('a', 40),
            '/projects/42/repository/merge_base?refs%5B%5D=' . str_repeat('a', 40) . '&refs%5B%5D=' . str_repeat('b', 40)] as $path) {
            self::assertTrue(GitLabCurlTransport::allowedPath($path), $path);
        }
        foreach (['/users', '/projects', '/projects?membership=false', '/projects/42/members', '/projects/42/repository/archive.zip',
            '/projects/0', '/projects/42/../../users', '/projects/42/repository/files/a/../b', '/projects/42/repository/tree?ref=main#x',
            "/projects/42\r\nX-Injected: 1", '//evil.example/user', '/projects/42/repository/files/x?ref=a b', 'https://evil.example/user'] as $path) {
            self::assertFalse(GitLabCurlTransport::allowedPath($path), $path);
        }
    }
}
