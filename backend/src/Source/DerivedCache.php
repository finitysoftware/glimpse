<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** A client-assembled result (e.g. a manifest built from many upstream pages) cached as one entry of the same owner and repository. */
interface DerivedCache
{
    public function derived(string $name): ?string;

    /** Stores a UTF-8 JSON document; results that depend on timing (e.g. cut short by a time budget) should not be stored. */
    public function storeDerived(string $name, string $body, int $ttl): void;
}
