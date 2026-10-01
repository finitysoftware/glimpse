<?php

declare(strict_types=1);

namespace Glimpse\Auth;

use SensitiveParameter;

/**
 * A session just issued by a sign-in or a password change: the token for this browser's cookie and the account it belongs to.
 *
 * @template T of UserContext|AdminContext
 */
final readonly class NewSession
{
    /** @param T $context */
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public UserContext|AdminContext $context,
    ) {}
}
