<?php

declare(strict_types=1);

namespace Glimpse\Visibility;

use Glimpse\Failure;
use Glimpse\Source\BranchName;

/** The owner's saved edits to one branch's file visibility, against the version and tree they were made on. */
final readonly class VisibilityChange
{
    public const MAX_OPERATIONS = 100;

    /** @param list<array{action: string, path: string}> $operations Selections and deselections, in the order they were made. */
    private function __construct(
        public string $branch,
        public int $expectedVersion,
        /** The branch head the owner's file list came from. */
        public string $sourceSha,
        /** selected or all; null keeps the saved mode. */
        public ?string $mode,
        public array $operations,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body): self
    {
        $branch = $body['branch'] ?? null;
        $expected = $body['expectedVersion'] ?? null;
        $sourceSha = $body['sourceSha'] ?? null;
        $mode = $body['mode'] ?? null;
        $operations = self::operations($body['operations'] ?? []);
        if (!is_string($branch) || !BranchName::valid($branch) || !is_int($expected) || $expected < 0
            || !is_string($sourceSha) || preg_match('/^[a-f0-9]{40}$/D', $sourceSha) !== 1 || !in_array($mode, [null, 'selected', 'all'], true)) {
            throw Failure::invalid('invalid_visibility', 'Send the branch, the loaded version and tree, and valid changes.');
        }
        if ($mode === 'all' && $operations !== []) {
            throw Failure::invalid('invalid_visibility', 'All files in this branch cannot be combined with individual selections.');
        }
        return new self($branch, $expected, $sourceSha, $mode, $operations);
    }

    /** @return list<array{action: string, path: string}> */
    private static function operations(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_OPERATIONS) {
            throw Failure::invalid('invalid_visibility', 'Save at most 100 visibility changes at a time.');
        }
        $operations = [];
        foreach ($value as $operation) {
            if (!is_array($operation) || !in_array($operation['action'] ?? null, ['select', 'deselect'], true) || !is_string($operation['path'] ?? null)) {
                throw Failure::invalid('invalid_visibility', 'Each change needs select or deselect and a path.');
            }
            $operations[] = ['action' => (string) $operation['action'], 'path' => VisibilityPaths::require($operation['path'])];
        }
        return $operations;
    }
}
