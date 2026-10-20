<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

use Sidus\FilterBundle\Definition\Field;

/**
 * A logical field path resolved against the Doctrine metadata, once per list: the associations it traverses and the
 * terminal mapped field. Immutable, holds no EntityManager. `$leaf` is null when the path ends on an association.
 */
final readonly class ResolvedField
{
    /**
     * @param class-string      $rootEntity
     * @param list<ResolvedStep> $steps
     * @param string|null       $leaf         mapped field name on the last entity (`address.city` for embeddables)
     * @param string|null       $leafType     DBAL type name of the terminal field
     * @param class-string|null $leafEntity   entity owning the terminal field
     */
    public function __construct(
        public Field $field,
        public string $rootEntity,
        public array $steps,
        public ?string $leaf,
        public ?string $leafType,
        public bool $leafNullable = true,
        public ?string $leafEntity = null,
    ) {
    }

    /** Index of the first to-many step, null when the whole path is to-one. */
    public function firstToMany(): ?int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->toMany) {
                return $index;
            }
        }

        return null;
    }

    public function isToMany(): bool
    {
        return null !== $this->firstToMany();
    }

    public function endsOnAssociation(): bool
    {
        return null === $this->leaf;
    }

    public function endsOnToMany(): bool
    {
        return [] !== $this->steps && $this->steps[\array_key_last($this->steps)]->toMany && $this->endsOnAssociation();
    }

    public function isJson(): bool
    {
        return [] !== $this->field->json;
    }

    /** Whether the value can be null for a reason other than "the column is nullable": a to-one step may be missing. */
    public function hasToOneStep(): bool
    {
        return array_any($this->steps, static fn (ResolvedStep $step): bool => !$step->toMany);
    }

    public function isNullable(): bool
    {
        return $this->leafNullable || $this->hasToOneStep();
    }

    public function __toString(): string
    {
        return (string) $this->field;
    }
}
