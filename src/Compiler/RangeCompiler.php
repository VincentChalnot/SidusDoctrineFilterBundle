<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\DoctrineFilterBundle\Query\ValueBinder;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Range;

final class RangeCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return Range::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Range);
        $field = $this->resolve($criterion, $context);

        return FieldAccess::matching($context, $field, static function (string $column) use ($criterion, $context, $field): DqlExpression {
            $conditions = [];
            if (null !== $criterion->min) {
                $conditions[] = $column.($criterion->minInclusive ? ' >= ' : ' > ').ValueBinder::bind($context, $field, $criterion->min);
            }
            if (null !== $criterion->max) {
                $conditions[] = $column.($criterion->maxInclusive ? ' <= ' : ' < ').ValueBinder::bind($context, $field, $criterion->max);
            }

            return DqlExpression::nullable(1 === \count($conditions) ? $conditions[0] : '('.implode(' AND ', $conditions).')');
        });
    }
}
