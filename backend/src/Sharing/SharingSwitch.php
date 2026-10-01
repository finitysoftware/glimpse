<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;

/** Sharing of one repository turned on or off: all of its visitor links at once. */
final readonly class SharingSwitch
{
    private function __construct(public bool $active) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $active = $body['active'] ?? null;
        if (!is_bool($active)) {
            throw Failure::invalid('invalid_input', 'Choose whether repository sharing is enabled.');
        }
        return new self($active);
    }
}
