<?php

declare(strict_types=1);

namespace Glimpse\Visibility;

/** The saved file visibility of one branch next to the branch's current files, as the Files editor shows it. */
final readonly class VisibilityProfile
{
    /**
     * @param list<string> $selected
     * @param list<string> $missing Selected paths that are not regular files of the current head (deleted or renamed); they stay path rules.
     * @param list<string>|null $manifest Every regular file of the head, or NULL when the tree is too large or incomplete.
     * @param array{paths: int, bytes: int, operations: int} $limits
     */
    public function __construct(
        public string $branch,
        public string $headSha,
        /** 'all' or 'selected'; NULL before the first save. */
        public ?string $mode,
        public int $version,
        /** The head the selection was saved on. */
        public ?string $sourceSha,
        public bool $treeChanged,
        public array $selected,
        public array $missing,
        public ?array $manifest,
        public int $fileCount,
        public bool $truncated,
        public array $limits,
    ) {}
}
