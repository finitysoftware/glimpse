<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

/** Which files of a branch a restricted viewer may open; an access link with selected files implements it. */
interface FilePolicy
{
    /**
     * Visible regular file paths of a branch, or NULL when every file of the branch is visible.
     *
     * @return array<string, true>|null
     */
    public function paths(string $branch): ?array;

    /** Changes whenever the rules change; a filtered History page is only valid for the version it was made with. */
    public function version(): int;
}
