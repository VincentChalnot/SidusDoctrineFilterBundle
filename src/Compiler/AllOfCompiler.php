<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\AllOf;
use Sidus\FilterBundle\Criterion\Criterion;

final class AllOfCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return AllOf::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof AllOf);
        $parts = array_map(static fn (Criterion $child): DqlExpression => $root->compile($child, $context), $criterion->criteria);
        $dql = '('.implode(' AND ', array_map(static fn (DqlExpression $part): string => $part->dql, $parts)).')';

        return array_any($parts, static fn (DqlExpression $part): bool => $part->nullable) ? DqlExpression::nullable($dql) : DqlExpression::safe($dql);
    }
}
