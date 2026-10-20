<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Not;

/**
 * Negation with the reference's two-valued logic: a condition that can be NULL (a comparison on a NULL column)
 * reads as "does not match", so its negation matches. `NOT (x)` alone would drop those rows.
 */
final class NotCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return Not::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Not);
        $inner = $root->compile($criterion->criterion, $context);

        return DqlExpression::safe($inner->nullable
            ? 'CASE WHEN ('.$inner->dql.') THEN 0 ELSE 1 END = 1'
            : 'NOT ('.$inner->dql.')');
    }
}
