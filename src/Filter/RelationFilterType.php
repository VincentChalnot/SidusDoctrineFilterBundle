<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Filter;

use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Equals;
use Sidus\FilterBundle\Criterion\In;
use Sidus\FilterBundle\Definition\Field;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Filter\NormalizedValue;
use Sidus\FilterBundle\Filter\Type\AbstractFilterType;
use Sidus\FilterBundle\Input\Violation;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * `type: relation` (v6 `entity`): filters on the identifier(s) of the entity at the end of an association path.
 * The attributes name the associations (`forum`, `topic.forum`, `tags`); the criterion is
 * `Equals(forum.id, id)`, or `In(tags.id, ids)` with `multiple: true`.
 *
 * To-one associations compile to a comparison of the foreign key (`root.forum = :p1`, no join), to-many ones to an
 * `EXISTS` (any related element has one of the ids).
 *
 * Options:
 *  - `multiple` (default false): accept a list of ids (a scalar is still accepted);
 *  - `identifier` (default `id`): the identifier field of the related entity;
 *  - `class`: the related entity, only read by the form mapper (EntityType needs it) and checked against the
 *    metadata at warm-up.
 */
final class RelationFilterType extends AbstractFilterType
{
    public static function getName(): string
    {
        return 'relation';
    }

    public static function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['multiple' => false, 'identifier' => 'id', 'class' => null]);
        $resolver->setAllowedTypes('multiple', 'bool');
        $resolver->setAllowedTypes('identifier', 'string');
        $resolver->setAllowedTypes('class', ['string', 'null']);
        $resolver->setAllowedValues('identifier', static fn (string $identifier): bool => 1 === preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier));
    }

    public static function getRequiredOperations(FilterDefinition $filter): array
    {
        return self::withAnyOf($filter, $filter->options['multiple'] ? [In::class] : [Equals::class]);
    }

    /**
     * The field a criterion of this filter compares: the identifier of the entity at the end of the attribute.
     */
    public static function fieldOf(FilterDefinition $filter, Field $attribute): Field
    {
        if ([] !== $attribute->json || null !== $attribute->cast) {
            throw new \LogicException(\sprintf('Relation filter "%s": the attribute "%s" must be an association path, not a JSON path.', $filter->key, $attribute));
        }

        return new Field($attribute->path.'.'.$filter->options['identifier']);
    }

    public function normalize(FilterDefinition $filter, mixed $raw): NormalizedValue
    {
        if (self::isBlank($raw)) {
            return NormalizedValue::empty();
        }
        $multiple = $filter->options['multiple'];
        if (\is_array($raw)) {
            if (!$multiple) {
                return NormalizedValue::invalid(new Violation('', 'Only one value is allowed.', $raw));
            }
            $items = array_values($raw);
        } else {
            $items = [$raw];
        }

        $violations = [];
        $ids = [];
        foreach ($items as $index => $item) {
            $path = $multiple ? (string) $index : '';
            if (!\is_scalar($item) || \is_bool($item) || '' === $item) {
                $violations[] = new Violation($path, 'Expected an identifier.', $item);
                continue;
            }
            $ids[] = $item;
        }
        if ([] !== $violations) {
            return NormalizedValue::invalid(...$violations);
        }

        return NormalizedValue::of($multiple ? $ids : $ids[0]);
    }

    public function buildCriterion(FilterDefinition $filter, mixed $value): ?Criterion
    {
        return $this->forAttributes($filter, static fn (Field $attribute): Criterion => \is_array($value)
            ? new In(self::fieldOf($filter, $attribute), $value)
            : new Equals(self::fieldOf($filter, $attribute), $value));
    }
}
