<?php

declare(strict_types=1);

namespace Glimpse\Repositories;

use JsonSerializable;

/** The branches a viewer may open and the one a preview starts on; a link offers only its own branches. */
final readonly class BranchChoices implements JsonSerializable
{
    /** @param list<string> $items */
    public function __construct(
        public array $items,
        public ?int $nextPage,
        public string $defaultBranch,
        public bool $allowHistory,
        public string $checkedAt = '',
        public string $contentSource = '',
        /** The link rules the list was read under; only for link visitors. */
        public ?int $policyVersion = null,
    ) {}

    /** A copy with another content source or link rules version. */
    public function with(?string $checkedAt = null, ?string $contentSource = null, ?int $policyVersion = null): self
    {
        return new self(
            $this->items,
            $this->nextPage,
            $this->defaultBranch,
            $this->allowHistory,
            $checkedAt ?? $this->checkedAt,
            $contentSource ?? $this->contentSource,
            $policyVersion ?? $this->policyVersion,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $json = get_object_vars($this);
        if ($this->policyVersion === null) {
            unset($json['policyVersion']);
        }
        return $json;
    }
}
