<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Metadata;

use Sidus\FilterBundle\Criterion\Contains;
use Sidus\FilterBundle\Criterion\EndsWith;
use Sidus\FilterBundle\Criterion\Equals;
use Sidus\FilterBundle\Criterion\In;
use Sidus\FilterBundle\Criterion\IsEmpty;
use Sidus\FilterBundle\Criterion\IsNull;
use Sidus\FilterBundle\Criterion\Matches;
use Sidus\FilterBundle\Criterion\Range;
use Sidus\FilterBundle\Criterion\StartsWith;

/**
 * Strict capability refusals that depend on the field (section 6): a criterion the provider cannot realise with the
 * reference meaning on this mapped type is refused with a reason, never approximated. Pure: used by the compilers
 * (as a last guard) and by the cache warmer / linter (to fail at deploy time).
 */
final class FieldCapabilities
{
    public const array TEXT_TYPES = ['string', 'text', 'ascii_string'];
    public const array NUMBER_TYPES = ['integer', 'smallint', 'bigint', 'float', 'smallfloat', 'decimal'];
    public const array DATE_TYPES = ['date', 'date_immutable', 'datetime', 'datetime_immutable', 'datetimetz', 'datetimetz_immutable'];
    private const array OPAQUE_TYPES = ['json', 'jsonb', 'json_array', 'json_document', 'array', 'simple_array', 'object', 'blob'];

    /**
     * @param class-string $criterionClass
     *
     * @return string|null null when supported, the refusal reason otherwise
     */
    public static function refusal(string $criterionClass, ResolvedField $field): ?string
    {
        if ($field->isJson()) {
            return 'JSON paths are not available';
        }
        if ($field->endsOnAssociation()) {
            return self::associationRefusal($criterionClass, $field);
        }
        $type = $field->leafType;
        $opaque = \in_array($type, self::OPAQUE_TYPES, true);

        return match ($criterionClass) {
            Equals::class, In::class => $opaque ? \sprintf('the type "%s" cannot be compared for equality', $type) : null,
            Contains::class, StartsWith::class, EndsWith::class, Matches::class => \in_array($type, self::TEXT_TYPES, true)
                ? null
                : \sprintf('text criteria need a string field, "%s" is of type "%s"', $field->field->path, $type),
            Range::class => \in_array($type, [...self::NUMBER_TYPES, ...self::DATE_TYPES], true)
                ? null
                : \sprintf('a range needs a number or date field, "%s" is of type "%s"', $field->field->path, $type),
            IsNull::class, IsEmpty::class => $opaque ? \sprintf('the type "%s" cannot be tested for emptiness', $type) : null,
            default => null,
        };
    }

    /**
     * @return string|null null when the field can be sorted on, the refusal reason otherwise
     */
    public static function sortRefusal(ResolvedField $field, ?string $aggregate): ?string
    {
        if ($field->isJson()) {
            return 'JSON paths are not available';
        }
        if (null !== $aggregate) {
            if (!\in_array($aggregate, ['count', 'min', 'max'], true)) {
                return \sprintf('unknown aggregate "%s" (count, min or max)', $aggregate);
            }
            if (!$field->isToMany()) {
                return \sprintf('"%s" is not a to-many path: an aggregate only applies to one', $field->field->path);
            }
            if ('count' !== $aggregate && $field->endsOnAssociation()) {
                return \sprintf('%s needs a scalar field, "%s" ends on an association', $aggregate, $field->field->path);
            }

            return 'count' !== $aggregate && \in_array($field->leafType, self::OPAQUE_TYPES, true) ? \sprintf('the type "%s" cannot be ordered', $field->leafType) : null;
        }
        if ($field->isToMany()) {
            return \sprintf('"%s" goes through a to-many association: a sort needs an aggregate (provider_options.doctrine.aggregate: count, min or max)', $field->field->path);
        }
        if ($field->endsOnAssociation()) {
            return \sprintf('"%s" ends on an association, sort on one of its fields', $field->field->path);
        }

        return \in_array($field->leafType, self::OPAQUE_TYPES, true) ? \sprintf('the type "%s" cannot be ordered', $field->leafType) : null;
    }

    private static function associationRefusal(string $criterionClass, ResolvedField $field): ?string
    {
        $last = $field->steps[\array_key_last($field->steps)];
        if (IsEmpty::class === $criterionClass) {
            if ($last->toMany && $field->firstToMany() < \count($field->steps) - 1) {
                return \sprintf('"%s" is a to-many association reached through another to-many association', $field->field->path);
            }
            if (!$last->toMany && null === $last->targetIdentifier && !$last->joinColumnOnRow) {
                return \sprintf('the target of "%s" has a composite identifier', $field->field->path);
            }

            return null;
        }
        if (IsNull::class === $criterionClass) {
            if ($last->toMany) {
                return \sprintf('"%s" is a collection: it is never null, use is_empty', $field->field->path);
            }
            if (!$last->joinColumnOnRow && null === $last->targetIdentifier) {
                return \sprintf('the target of "%s" has a composite identifier', $field->field->path);
            }

            return null;
        }

        return \sprintf('"%s" ends on an association: compare one of its fields (e.g. "%s.%s")', $field->field->path, $field->field->path, $last->targetIdentifier ?? 'id');
    }
}
