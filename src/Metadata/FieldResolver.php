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
     * @param class-string $rootEntity
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        public readonly string $rootEntity,
    ) {
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
        foreach ($segments as $index => $segment) {
            $name = $embedded.$segment;
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
                );
                $steps[] = $step;
                if ($index === $last) {
                    return new ResolvedField($field, $this->rootEntity, $steps, null, null);
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

                return new ResolvedField($field, $this->rootEntity, $steps, $name, $mapping->type, $mapping->nullable ?? false, $metadata->getName());
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
