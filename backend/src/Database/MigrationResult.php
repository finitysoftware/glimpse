<?php

declare(strict_types=1);

namespace Glimpse\Database;

final readonly class MigrationResult
{
    /** @param list<string> $applied */
    public function __construct(public array $applied, public ?string $blockedBy = null, public ?string $reason = null) {}

    public function blocked(): bool
    {
        return $this->blockedBy !== null;
    }
}
