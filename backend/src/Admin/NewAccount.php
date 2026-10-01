<?php

declare(strict_types=1);

namespace Glimpse\Admin;

use Glimpse\Accounts\Username;
use Glimpse\Failure;
use SensitiveParameter;

/** An account the admin panel creates, optionally with a GitHub token and a GitLab connection. */
final readonly class NewAccount
{
    private function __construct(
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public bool $admin,
        public bool $mustChangePassword,
        /** Saved only when not empty; it is verified with GitHub first. */
        #[SensitiveParameter]
        public ?string $githubToken,
        public ?string $gitlabUrl,
        /** Saved only when not empty, together with $gitlabUrl; both are verified with GitLab first. */
        #[SensitiveParameter]
        public ?string $gitlabToken,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $firstName = AdminUserInput::name($body['firstName'] ?? null, 'first name');
        $lastName = AdminUserInput::name($body['lastName'] ?? null, 'last name');
        $email = AdminUserInput::email($body['email'] ?? null);
        $username = Username::normalize(is_string($body['username'] ?? null) ? $body['username'] : '');
        $password = $body['password'] ?? null;
        if (!is_string($password)) {
            throw Failure::invalid('invalid_password', 'Enter a password.');
        }
        $admin = AdminUserInput::admin($body['role'] ?? 'user');
        $mustChange = AdminUserInput::flag($body['requirePasswordChange'] ?? false, 'requirePasswordChange');
        $githubToken = $body['githubToken'] ?? null;
        if ($githubToken !== null && !is_string($githubToken)) {
            throw Failure::invalid('invalid_input', 'Invalid GitHub token.');
        }
        $gitlabUrl = $body['gitlabUrl'] ?? null;
        $gitlabToken = $body['gitlabToken'] ?? null;
        if (($gitlabToken !== null && !is_string($gitlabToken)) || ($gitlabUrl !== null && !is_string($gitlabUrl))
            || (is_string($gitlabToken) && trim($gitlabToken) !== '' && (!is_string($gitlabUrl) || trim($gitlabUrl) === ''))) {
            throw Failure::invalid('invalid_input', 'Enter the GitLab URL together with the GitLab token.');
        }
        return new self($firstName, $lastName, $email, $username, $password, $admin, $mustChange, $githubToken, $gitlabUrl, $gitlabToken);
    }
}
