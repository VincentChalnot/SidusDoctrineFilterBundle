<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\IsNull;

/**
 * Null, or absent: the column is NULL, a to-one association has no target. Through a collection, an empty fan-out
 * counts too (reference: "no related element" reads as null).
 */
final class IsNullCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return IsNull::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof IsNull);
        $field = self::nullTarget($this->resolve($criterion, $context));

        return FieldAccess::matching($context, $field, static fn (string $column): DqlExpression => DqlExpression::safe($column.' IS NULL'), true);
    }

    /**
     * The nullable column that stands for a to-one association: its foreign key when it lives on the row (no join
     * needed), the identifier of the joined target otherwise.
     */
    public static function nullTarget(ResolvedField $field): ResolvedField
    {
        if (!$field->endsOnAssociation()) {
            return $field;
        }
        $last = $field->steps[\array_key_last($field->steps)];
        if ($last->joinColumnOnRow) {
            return new ResolvedField($field->field, $field->rootEntity, \array_slice($field->steps, 0, -1), $last->name, null, true);
        }

        return new ResolvedField($field->field, $field->rootEntity, $field->steps, $last->targetIdentifier, null, true);
    }
}
