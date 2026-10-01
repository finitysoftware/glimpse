<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** A code hosting platform that repositories come from. Each user has at most one connection per platform. */
enum Provider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';

    /** GitHub.com is the only GitHub instance; GitLab instances come from each user's connection. */
    public const GITHUB_INSTANCE = 'https://github.com';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
        };
    }

    /** Error codes keep the platform prefix, e.g. github_busy and gitlab_busy. */
    public function code(string $suffix): string
    {
        return $this->value . '_' . $suffix;
    }

    public function connectionTable(): string
    {
        return match ($this) {
            self::GitHub => 'github_connections',
            self::GitLab => 'gitlab_connections',
        };
    }
}
