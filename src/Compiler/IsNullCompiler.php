<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

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
        $field = $this->resolve($criterion, $context)->asForeignKey();

        return FieldAccess::matching($context, $field, static fn (string $column): DqlExpression => DqlExpression::safe($column.' IS NULL'), true);
    }
}
