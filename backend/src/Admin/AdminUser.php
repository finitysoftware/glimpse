<?php

declare(strict_types=1);

namespace Glimpse\Admin;

/** An account as the admin list shows it; never a password or token. */
final readonly class AdminUser
{
    /**
     * @param 'admin'|'user' $role
     * @param array{status: string, login: string|null} $github
     * @param array{status: string, username: string|null, baseUrl: string|null} $gitlab
     * @param array{active: int, total: int} $links
     */
    public function __construct(
        public int $id,
        public string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public string $role,
        public bool $active,
        /** Changes with every edit; a change names the version it was made on. */
        public int $version,
        public array $github,
        public array $gitlab,
        public array $links,
        public ?string $lastSignInAt,
        public ?string $createdAt,
    ) {}
}
