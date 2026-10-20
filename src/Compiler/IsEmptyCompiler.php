<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\IsEmpty;

/**
 * Null, empty string, or empty collection. A collection is empty when no element exists (`NOT EXISTS`); a string
 * column is empty when NULL or `''`; other scalars are empty when NULL.
 */
final class IsEmptyCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return IsEmpty::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof IsEmpty);
        $field = $this->resolve($criterion, $context);
        if ($field->endsOnToMany()) {
            return FieldAccess::none($context, $field);
        }
        $field = IsNullCompiler::nullTarget($field);
        $text = \in_array($field->leafType, FieldCapabilities::TEXT_TYPES, true);

        return FieldAccess::matching(
            $context,
            $field,
            static fn (string $column): DqlExpression => DqlExpression::safe($text ? '('.$column." IS NULL OR $column = '')" : $column.' IS NULL'),
            true,
        );
    }
}
