<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

/**
 * Everything about a list that is resolved once and shared by all its requests: the entity, its identifier, the
 * field resolver (memoising) and the per-field provider options. The memo inside the resolver only caches immutable
 * metadata, never request data.
 */
final readonly class ListMapping
{
    /**
     * @param class-string                          $entity
     * @param array<string, array<string, mixed>>   $fieldOptions provider options by field (`title`, `author.name`)
     */
    public function __construct(
        public string $entity,
        public string $identifier,
        public FieldResolver $fields,
        public bool $binarySort = false,
        public array $fieldOptions = [],
    ) {
    }
}
