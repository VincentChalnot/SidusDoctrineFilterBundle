<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\DoctrineFilterBundle\Query\ValueBinder;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\In;

final class InCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return In::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof In);
        $field = $this->resolve($criterion, $context);
        $values = array_values(array_filter($criterion->values, static fn (mixed $value): bool => null !== $value));
        if ([] === $values) {
            return DqlExpression::never();
        }

        return FieldAccess::matching($context, $field, static fn (string $column): DqlExpression => DqlExpression::nullable(
            $column.' IN ('.ValueBinder::bindList($context, $field, $values).')',
        ));
    }
}
