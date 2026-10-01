<?php

declare(strict_types=1);

namespace Glimpse\Auth;

/** The verified administrator of an admin panel request, derived only from its SQL session. */
final readonly class AdminContext
{
    public function __construct(
        public int $userId,
        public string $username,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public int $sessionId,
        public int $sessionVersion,
        public bool $mustChangePassword,
        public string $csrfToken,
    ) {}

    /** @return array{admin: array{id: int, username: string, firstName: string|null, lastName: string|null, email: string|null}, csrfToken: string, passwordChangeRequired: bool} */
    public function session(): array
    {
        return ['admin' => ['id' => $this->userId, 'username' => $this->username, 'firstName' => $this->firstName,
            'lastName' => $this->lastName, 'email' => $this->email], 'csrfToken' => $this->csrfToken, 'passwordChangeRequired' => $this->mustChangePassword];
    }
}
