<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Accounts\Username;
use Glimpse\Failure;
use SensitiveParameter;

/** What the admin panel changes on an account; null means unchanged. */
final readonly class AccountChanges
{
    /** @param array<string, string|null> $profile New values of the users columns first_name, last_name, email and username. */
    private function __construct(
        /** The account version the admin saw, so a change made elsewhere is not overwritten. */
        public int $expectedVersion,
        public array $profile,
        /** '' keeps the current password. */
        #[SensitiveParameter]
        public string $password,
        public ?bool $mustChangePassword,
        public ?bool $admin,
        public ?bool $active,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $expected = AdminUserInput::version($body['expectedVersion'] ?? null);
        $profile = [];
        if (array_key_exists('firstName', $body)) {
            $profile['first_name'] = AdminUserInput::name($body['firstName'], 'first name');
        }
        if (array_key_exists('lastName', $body)) {
            $profile['last_name'] = AdminUserInput::name($body['lastName'], 'last name');
        }
        if (array_key_exists('email', $body)) {
            $profile['email'] = AdminUserInput::email($body['email']);
        }
        if (array_key_exists('username', $body)) {
            $profile['username'] = Username::normalize(is_string($body['username']) ? $body['username'] : '');
        }
        $password = $body['password'] ?? '';
        if (!is_string($password)) {
            throw Failure::invalid('invalid_password', 'Enter a password.');
        }
        $mustChange = array_key_exists('requirePasswordChange', $body)
            ? AdminUserInput::flag($body['requirePasswordChange'], 'requirePasswordChange')
            : null;
        $admin = array_key_exists('role', $body) ? AdminUserInput::admin($body['role']) : null;
        $active = array_key_exists('active', $body) ? AdminUserInput::flag($body['active'], 'active') : null;
        return new self($expected, $profile, $password, $mustChange, $admin, $active);
    }
}
