<?php

declare(strict_types=1);

namespace Glimpse\Connections;

use Glimpse\Failure;
use SensitiveParameter;

/** A token to verify and save, the connection version it replaces and, for GitLab, the address of the instance. */
final readonly class TokenChange
{
    private function __construct(
        #[SensitiveParameter]
        public string $token,
        public int $expectedVersion,
        public string $baseUrl = '',
    ) {}

    /** @param array<string, mixed> $body */
    public static function github(array $body): self
    {
        $token = $body['token'] ?? null;
        $expected = $body['expectedVersion'] ?? null;
        if (!is_string($token) || !is_int($expected) || $expected < 0) {
            throw Failure::invalid('invalid_input', 'Enter a GitHub token and the connection version it replaces.');
        }
        return new self(trim($token), $expected);
    }

    /** @param array<string, mixed> $body */
    public static function gitlab(array $body): self
    {
        $url = $body['baseUrl'] ?? null;
        $token = $body['token'] ?? null;
        $expected = $body['expectedVersion'] ?? null;
        if (!is_string($url) || !is_string($token) || !is_int($expected) || $expected < 0) {
            throw Failure::invalid('invalid_input', 'Enter the GitLab URL, a token and the connection version it replaces.');
        }
        return new self(trim($token), $expected, $url);
    }
}
