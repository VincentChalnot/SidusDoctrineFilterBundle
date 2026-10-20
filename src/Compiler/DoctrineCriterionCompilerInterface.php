<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Provider\CriterionCompilerInterface;

/**
 * Compiles one criterion class to a DQL condition. Autoconfigured (tag `sidus_doctrine_filter.compiler`): an
 * application or another package adds a criterion by registering a compiler, or replaces one by decorating it.
 * Composites compile their children through `$root`.
 */
interface DoctrineCriterionCompilerInterface extends CriterionCompilerInterface
{
    public function compile(Criterion $criterion, DoctrineCompiler $root, DoctrineQueryContext $context): DqlExpression;
}
