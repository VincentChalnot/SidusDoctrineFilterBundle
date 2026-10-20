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

    /**
     * The DBAL type of the values compared: the mapped type of the terminal field, or, for a JSON path, the type of
     * the cast (`string` without one: JSON values are read as text).
     */
    public function valueType(): ?string
    {
        if (!$this->isJson()) {
            return $this->leafType;
        }

        return match ($this->field->cast) {
            'integer' => 'integer',
            'float' => 'float',
            'boolean' => 'boolean',
            'date' => 'datetimetz_immutable',
            default => 'string',
        };
    }

    /**
     * `forum.id` through an owning to-one association is the foreign key of the row: `root.forum`, no join needed.
     * Returns that shorter field, or this one when the path is not of that shape.
     */
    public function collapseIdentifier(): self
    {
        if (null === $this->leaf || $this->isJson() || [] === $this->steps) {
            return $this;
        }
        $last = $this->steps[\array_key_last($this->steps)];
        if ($last->toMany || !$last->joinColumnOnRow || null !== $last->join || $last->targetIdentifier !== $this->leaf) {
            return $this;
        }

        return new self($this->field, $this->rootEntity, \array_slice($this->steps, 0, -1), $last->name, $last->targetIdentifierType, true, $last->source);
    }

    /**
     * The nullable column that stands for an association in a null / emptiness test or a join condition: its
     * foreign key when it lives on the row (no join needed), the identifier of the joined target otherwise.
     */
    public function asForeignKey(): self
    {
        if (!$this->endsOnAssociation()) {
            return $this;
        }
        $last = $this->steps[\array_key_last($this->steps)];
        if ($last->joinColumnOnRow) {
            return new self($this->field, $this->rootEntity, \array_slice($this->steps, 0, -1), $last->name, null, true);
        }

        return new self($this->field, $this->rootEntity, $this->steps, $last->targetIdentifier, null, true);
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
        return $this->leafNullable || $this->hasToOneStep() || $this->isJson();
    }

    public function __toString(): string
    {
        return (string) $this->field;
    }
}
