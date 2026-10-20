<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\DoctrineFilterBundle\Query\ValueBinder;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Equals;

final class EqualsCompiler extends AbstractDoctrineCompiler
{
    public static function getCriterionClass(): string
    {
        return Equals::class;
    }

    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression
    {
        \assert($criterion instanceof Equals);
        $field = $this->resolve($criterion, $context);
        if (null === $criterion->value) {
            return DqlExpression::never(); // null never matches a comparison
        }

        return FieldAccess::matching($context, $field, static fn (string $column): DqlExpression => DqlExpression::nullable(
            $column.' = '.ValueBinder::bind($context, $field, $criterion->value),
        ));
    }
}
