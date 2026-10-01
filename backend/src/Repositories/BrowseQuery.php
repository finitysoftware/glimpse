<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use Glimpse\Failure;
use Glimpse\Fields;

/** What a preview request asks for: a folder, a file, search results, History, one commit or the branches. */
final readonly class BrowseQuery
{
    public const KINDS = ['directory', 'file', 'history', 'commit', 'branches', 'search'];

    private function __construct(
        public string $kind,
        public string $path,
        /** A full commit SHA, or '' for the head of the branch. */
        public string $sha,
        /** A branch, tag or commit for links without branch restrictions; '' for the default branch. */
        public string $ref,
        public string $branch,
        /** Refresh: read the platform again instead of the server cache. */
        public bool $refresh,
        public int $page,
        /** Where History filtered to shared files continues. */
        public string $cursor,
        /** The text of a file search. */
        public string $search,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $path = Fields::text($query, 'path');
        $sha = Fields::text($query, 'sha');
        $ref = Fields::text($query, 'ref');
        $branch = Fields::text($query, 'branch');
        $kind = Fields::text($query, 'kind', 'directory');
        $refresh = Fields::text($query, 'refresh', '0');
        if (!in_array($kind, self::KINDS, true) || ($sha !== '' && !preg_match('/^[a-f0-9]{40}$/D', $sha))) {
            throw Failure::invalid('invalid_browse_request', 'Invalid preview request.');
        }
        if (!in_array($refresh, ['0', '1'], true)) {
            throw Failure::invalid('invalid_browse_request', 'Invalid refresh request.');
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > ($kind === 'commit' ? 100 : 1000000) || ($kind === 'commit' && $sha === '')) {
            throw Failure::invalid('invalid_history_request', 'Invalid history page or commit SHA.');
        }
        $cursor = Fields::text($query, 'cursor');
        $search = Fields::text($query, 'q');
        return new self($kind, $path, $sha, $ref, $branch, $refresh === '1', $page, $cursor, $search);
    }
}
