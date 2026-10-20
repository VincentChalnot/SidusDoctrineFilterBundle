<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

/**
 * One association (or named join) traversed by a field path. Immutable.
 */
final readonly class ResolvedStep
{
    /**
     * @param class-string $source           entity owning the association
     * @param class-string $target           entity reached
     * @param string|null  $mappedBy         the field of `$target` pointing back, for a collection mapped by a foreign key on the target
     * @param bool         $joinColumnOnRow  a to-one whose foreign key lives on the source row (`IS NULL` needs no join)
     * @param string|null  $targetIdentifier single identifier field of the target, null when composite
     * @param NamedJoin|null $join           set when the step is a named join (always the first step of a path)
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $target,
        public bool $toMany,
        public ?string $mappedBy = null,
        public bool $joinColumnOnRow = false,
        public ?string $targetIdentifier = null,
        public ?NamedJoin $join = null,
    ) {
    }

    /** A step that joins the collection instead of correlating an EXISTS when asked to (named join `shared: true`). */
    public function isSharedJoin(): bool
    {
        return null !== $this->join && $this->join->shared;
    }
}
