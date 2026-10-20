<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Sort;

/**
 * Dispatches a criterion to the compiler registered for its class. Built once per factory, immutable.
 */
final readonly class DoctrineCompiler
{
    /** @var array<class-string<Criterion>, DoctrineCriterionCompilerInterface> */
    private array $compilers;

    private ?DoctrineSortCompilerInterface $sort;

    /**
     * @param iterable<DoctrineCriterionCompilerInterface|DoctrineSortCompilerInterface> $compilers
     */
    public function __construct(iterable $compilers)
    {
        $map = [];
        $sort = null;
        foreach ($compilers as $compiler) {
            if ($compiler instanceof DoctrineSortCompilerInterface) {
                $sort = $compiler;
                continue;
            }
            $map[$compiler::getCriterionClass()] = $compiler;
        }
        $this->compilers = $map;
        $this->sort = $sort;
    }

    public function compile(Criterion $criterion, DoctrineQueryContext $context): DqlExpression
    {
        $compiler = $this->compilers[$criterion::class] ?? throw new \LogicException(\sprintf('The doctrine provider has no compiler for criterion %s.', $criterion::class));

        return $compiler->compile($criterion, $this, $context);
    }

    public function sort(Sort $sort, DoctrineQueryContext $context): void
    {
        ($this->sort ?? throw new \LogicException('The doctrine provider has no compiler for the Sort criterion.'))->apply($sort, $context);
    }
}
