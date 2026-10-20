<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

/**
 * Everything about a list that is resolved once and shared by all its requests: the entity, its identifier, the
 * field resolver (memoising). The memo inside the resolver only caches immutable
 * metadata, never request data.
 */
final readonly class ListMapping
{
    /**
     * @param class-string                          $entity
     */
    public function __construct(
        public string $entity,
        public string $identifier,
        public FieldResolver $fields,
        public bool $binarySort = false,
    ) {
    }
}
