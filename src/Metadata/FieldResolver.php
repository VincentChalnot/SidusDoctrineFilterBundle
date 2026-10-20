<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\Persistence\ManagerRegistry;
use Sidus\FilterBundle\Definition\Field;
use Sidus\FilterBundle\Exception\ConfigurationException;

/**
 * Resolves the field paths of one list against the Doctrine metadata and memoises the immutable results: after the
 * first resolution of a path the entity manager is not touched again. Resolution only ever reads configuration
 * (filter attributes and sort columns), never request input.
 *
 * Failures are {@see ConfigurationException}s: unknown segment, a scalar in the middle of a path, a composite or
 * missing identifier.
 */
final class FieldResolver
{
    /** @var array<string, ResolvedField> */
    private array $memo = [];

    /**
     * @param class-string               $rootEntity
     * @param array<string, NamedJoin>   $joins      the named joins of the list, by alias
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        public readonly string $rootEntity,
        private readonly array $joins = [],
    ) {
    }

    /** @return array<string, NamedJoin> */
    public function joins(): array
    {
        return $this->joins;
    }

    /**
     * Checks the named joins against the metadata: the alias does not shadow a field of the root, the entity is
     * managed, `on.field` exists on it, `on.equals` is a to-one path of the root, or starts with another named join that
     * is joined (cardinality one, or many with `shared`), without cycles.
     *
     * @throws ConfigurationException
     */
    public function validateJoins(): void
    {
        $root = $this->metadata($this->rootEntity);
        foreach ($this->joins as $alias => $join) {
            $where = \sprintf('Join "%s"', $alias);
            if ($root->hasField($alias) || $root->hasAssociation($alias) || isset($root->embeddedClasses[$alias])) {
                throw new ConfigurationException(\sprintf('%s: the alias shadows the field or association "%s" of %s.', $where, $alias, $root->getName()));
            }
            $target = $this->metadata($join->entity);
            if (!$target->hasField($join->onField) && !$target->hasAssociation($join->onField)) {
                throw new ConfigurationException(\sprintf('%s: %s has no field or association "%s" (on.field).', $where, $target->getName(), $join->onField));
            }
            $firstSegment = explode('.', $join->equals)[0];
            if (isset($this->joins[$firstSegment])) {
                $referenced = $this->joins[$firstSegment];
                if ($referenced->isMany() && !$referenced->shared) {
                    throw new ConfigurationException(\sprintf('%s: on.equals "%s" goes through the named join "%s" of cardinality many, which is a correlated EXISTS and cannot be referenced: declare it with shared: true (the chain is then joined, in DISTINCT mode).', $where, $join->equals, $firstSegment));
                }
                if ($this->dependsOn($alias, $firstSegment)) {
                    throw new ConfigurationException(\sprintf('%s: on.equals "%s" makes the named joins depend on each other in a cycle.', $where, $join->equals));
                }
            }
            try {
                $equals = $this->resolve($join->equals);
            } catch (ConfigurationException $e) {
                throw new ConfigurationException(\sprintf('%s: on.equals: %s', $where, $e->getMessage()), previous: $e);
            }
            if (array_any($equals->steps, static fn (ResolvedStep $step): bool => $step->toMany && !$step->isSharedJoin()) || $equals->isJson()) {
                throw new ConfigurationException(\sprintf('%s: on.equals "%s" must be a to-one path (it goes through a collection or a JSON path).', $where, $join->equals));
            }
        }
    }

    public function resolve(Field|string $field): ResolvedField
    {
        $field = Field::of($field);
        $key = (string) $field;

        return $this->memo[$key] ??= $this->doResolve($field);
    }

    /**
     * The single identifier field of the root entity.
     */
    public function identifier(): string
    {
        $identifiers = $this->metadata($this->rootEntity)->getIdentifierFieldNames();
        if (1 !== \count($identifiers)) {
            throw new ConfigurationException(\sprintf('Entity %s has a composite identifier: declare the list option "identifier".', $this->rootEntity));
        }

        return $identifiers[0];
    }

    private function doResolve(Field $field): ResolvedField
    {
        $metadata = $this->metadata($this->rootEntity);
        $segments = $field->segments();
        $last = \count($segments) - 1;
        $steps = [];
        $embedded = '';
        if (null !== $field->cast && [] === $field->json) {
            throw new ConfigurationException(\sprintf('Field path "%s": a cast applies to a JSON path only (%s->key::%s).', $field->path, $field->path, $field->cast));
        }
        foreach ($segments as $index => $segment) {
            $name = $embedded.$segment;
            if (0 === $index && isset($this->joins[$segment])) {
                $join = $this->joins[$segment];
                $target = $this->metadata($join->entity);
                $identifiers = $target->getIdentifierFieldNames();
                $steps[] = new ResolvedStep($segment, $this->rootEntity, $target->getName(), $join->isMany(), null, false, 1 === \count($identifiers) ? $identifiers[0] : null, $join, 1 === \count($identifiers) ? $target->getTypeOfField($identifiers[0]) : null);
                if (0 === $last) {
                    return $this->finish(new ResolvedField($field, $this->rootEntity, $steps, null, null));
                }
                $metadata = $target;
                continue;
            }
            if ('' === $embedded && $metadata->hasAssociation($segment)) {
                $mapping = $metadata->getAssociationMapping($segment);
                $target = $this->metadata($mapping->targetEntity);
                $identifiers = $target->getIdentifierFieldNames();
                $step = new ResolvedStep(
                    name: $segment,
                    source: $metadata->getName(),
                    target: $target->getName(),
                    toMany: $mapping->isToMany(),
                    mappedBy: $mapping->isOneToMany() && $mapping instanceof InverseSideMapping ? $mapping->mappedBy : null,
                    joinColumnOnRow: $mapping->isToOneOwningSide(),
                    targetIdentifier: 1 === \count($identifiers) ? $identifiers[0] : null,
                    targetIdentifierType: 1 === \count($identifiers) ? $target->getTypeOfField($identifiers[0]) : null,
                );
                $steps[] = $step;
                if ($index === $last) {
                    return $this->finish(new ResolvedField($field, $this->rootEntity, $steps, null, null));
                }
                $metadata = $target;
                continue;
            }
            if (isset($metadata->embeddedClasses[$name])) {
                if ($index === $last) {
                    throw new ConfigurationException(\sprintf('Field path "%s": "%s" is an embedded object, select one of its fields (e.g. "%s.<field>").', $field->path, $name, $field->path));
                }
                $embedded = $name.'.';
                continue;
            }

            if ($metadata->hasField($name)) {
                if ($index !== $last) {
                    throw new ConfigurationException(\sprintf('Field path "%s": "%s" of %s is a scalar field, it cannot be traversed.', $field->path, $name, $metadata->getName()));
                }
                $mapping = $metadata->getFieldMapping($name);

                return $this->finish(new ResolvedField($field, $this->rootEntity, $steps, $name, $mapping->type, $mapping->nullable ?? false, $metadata->getName()));
            }
            throw new ConfigurationException(\sprintf(
                'Field path "%s": %s has no field or association "%s", available: %s.',
                $field->path,
                $metadata->getName(),
                $name,
                implode(', ', [...$metadata->getFieldNames(), ...$metadata->getAssociationNames()]),
            ));
        }

        throw new ConfigurationException(\sprintf('Field path "%s" could not be resolved.', $field->path));
    }

    /** Whether the named join `$from` (transitively) refers to `$to` in its `equals` path. */
    private function dependsOn(string $from, string $to, array $seen = []): bool
    {
        if ($from === $to) {
            return true;
        }
        if (isset($seen[$to])) {
            return false;
        }
        $next = explode('.', $this->joins[$to]->equals)[0];

        return isset($this->joins[$next]) && $this->dependsOn($from, $next, [...$seen, $to => true]);
    }

    private function finish(ResolvedField $resolved): ResolvedField
    {
        if ($resolved->isJson() && !\in_array($resolved->leafType, FieldCapabilities::JSON_TYPES, true)) {
            throw new ConfigurationException(\sprintf('Field path "%s": a JSON path needs a JSON field, "%s" is of type "%s".', $resolved->field->path, $resolved->leaf ?? $resolved->field->path, $resolved->leafType ?? 'association'));
        }

        return $resolved;
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $manager = $this->registry->getManagerForClass($class)
            ?? throw new ConfigurationException(\sprintf('%s is not a Doctrine managed entity.', $class));
        $metadata = $manager->getClassMetadata($class);
        \assert($metadata instanceof ClassMetadata);

        return $metadata;
    }
}
