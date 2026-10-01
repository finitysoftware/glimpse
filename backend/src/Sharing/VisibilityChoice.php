<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

/**
 * The file visibility a link uses for one of its branches: a saved profile version, all files, or (when a link
 * is edited) the rules it has now. Whether the choices fit the link is checked when the link is saved, after
 * the link and its repository are known, so those failures keep coming first.
 */
final readonly class VisibilityChoice
{
    private function __construct(
        /** '' when the request named no branch; no link has such a branch, so saving rejects it. */
        public string $branch,
        public ?int $profileVersion,
        public bool $allFiles,
        public bool $keep,
    ) {}

    public static function keep(string $branch): self
    {
        return new self($branch, null, false, true);
    }

    /** @return list<self>|null null when the request sent no list of choices. */
    public static function listFrom(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? self::fromList($value) : null;
    }

    /**
     * @param list<mixed> $entries
     * @return list<self>
     */
    public static function fromList(array $entries): array
    {
        $choices = [];
        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $branch = $entry['branch'] ?? null;
            $version = $entry['profileVersion'] ?? null;
            $choices[] = new self(
                is_string($branch) ? $branch : '',
                is_int($version) && $version >= 1 ? $version : null,
                ($entry['allFiles'] ?? null) === true,
                ($entry['keep'] ?? null) === true,
            );
        }
        return $choices;
    }
}
