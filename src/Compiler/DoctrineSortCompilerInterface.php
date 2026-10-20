<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Sort;
use Sidus\FilterBundle\Provider\CriterionCompilerInterface;

/**
 * Compiles the `Sort` criterion: applies the ordering (and its hidden selects) to the query of the context.
 */
interface DoctrineSortCompilerInterface extends CriterionCompilerInterface
{
    public function apply(Sort $sort, DoctrineQueryContext $context): void;
}
