<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineCompiler;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\ListMapping;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\FilterBundle\Criterion\Criterion;
use Sidus\FilterBundle\Criterion\Sort;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Provider\AbstractQueryHandler;
use Sidus\FilterBundle\Provider\CountStrategyInterface;
use Sidus\FilterBundle\Provider\QueryContextInterface;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;

/**
 * The per-request handler of the `doctrine` provider (single use): creates the QueryBuilder, runs the customizers,
 * compiles the criteria into `andWhere`s and the sort into one ORDER BY, fetches one page with a plain
 * LIMIT/OFFSET and counts on a clone without ORDER BY.
 */
final class DoctrineQueryHandler extends AbstractQueryHandler
{
    /** @var list<\Closure(DoctrineQueryContext): void> */
    private array $customizers = [];

    public function __construct(
        ListDefinition $definition,
        FilterTypeRegistry $filterTypes,
        ?CountStrategyInterface $countStrategy,
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineCompiler $compiler,
        private readonly ListMapping $mapping,
        private readonly Dialect $dialect,
    ) {
        parent::__construct($definition, $filterTypes, $countStrategy);
    }

    /**
     * A handler that also runs `$customizer` on the freshly created query, before the criteria are applied: add
     * selects, joins or conditions. It receives the root alias and the {@see JoinRegistry} (to reuse a shared
     * to-one alias instead of adding its own join). A customizer that joins a collection must call
     * {@see DoctrineQueryContext::requireDistinct()}.
     *
     * @param \Closure(DoctrineQueryContext): void $customizer
     */
    public function withCustomizer(\Closure $customizer): static
    {
        $clone = clone $this;
        $clone->customizers[] = $customizer;

        return $clone;
    }

    protected function createContext(): QueryContextInterface
    {
        $alias = DoctrineQueryHandlerFactory::ROOT_ALIAS;
        $queryBuilder = $this->entityManager->createQueryBuilder()->select($alias)->from($this->mapping->entity, $alias);
        $context = new DoctrineQueryContext(
            $this->entityManager,
            $queryBuilder,
            new JoinRegistry($queryBuilder, $alias, $this->mapping->fields),
            $this->mapping,
            $this->dialect,
        );
        foreach ($this->customizers as $customizer) {
            $customizer($context);
        }

        return $context;
    }

    protected function applyCriterion(QueryContextInterface $context, Criterion $criterion): void
    {
        \assert($context instanceof DoctrineQueryContext);
        $context->queryBuilder()->andWhere($this->compiler->compile($criterion, $context)->dql);
    }

    protected function applySort(QueryContextInterface $context, Sort $sort): void
    {
        \assert($context instanceof DoctrineQueryContext);
        $this->compiler->sort($sort, $context);
    }

    protected function fetch(QueryContextInterface $context, int $offset, int $limit): iterable
    {
        \assert($context instanceof DoctrineQueryContext);
        $queryBuilder = $context->queryBuilder();
        if ([] === $queryBuilder->getDQLPart('orderBy')) {
            Ordering::add($queryBuilder, $context->identifierPath()); // stable pages even without a sort filter
        }
        $query = $queryBuilder->setFirstResult($offset)->setMaxResults($limit)->getQuery();
        if ($context->isDistinct()) {
            return iterator_to_array(new Paginator($query, true), false);
        }

        return $query->getResult();
    }

    protected function countNative(QueryContextInterface $context): int
    {
        \assert($context instanceof DoctrineQueryContext);
        $count = clone $context->queryBuilder();
        $count->resetDQLPart('orderBy')
            ->select('COUNT('.($context->isDistinct() ? 'DISTINCT ' : '').$context->identifierPath().')')
            ->setFirstResult(null)
            ->setMaxResults(null);

        return (int) $count->getQuery()->getSingleScalarResult();
    }
}
