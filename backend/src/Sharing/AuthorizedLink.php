<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Repositories\BrowseAccess;
use Glimpse\Repositories\RepositoryRef;

/** A link this visitor session may open right now, and what it opens: the owner's repository with the link's branches, History and files. */
final readonly class AuthorizedLink
{
    public function __construct(
        /** The link owner; the visitor reads through the owner's connection and cache. */
        public int $ownerId,
        public RepositoryRef $ref,
        /** The platform instance the link was made on. */
        public string $instance,
        /** The repository's ID on its platform, so a different repository under the same name never opens. */
        public int $externalId,
        public BrowseAccess $access,
        public SharePolicy $policy,
    ) {}
}
