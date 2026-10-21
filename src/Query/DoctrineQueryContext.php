<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Metadata\ListMapping;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedStep;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\FilterBundle\Criterion\FieldCriterion;
use Sidus\FilterBundle\Provider\QueryContextInterface;

/**
 * The per-request query: the QueryBuilder, its joins and its parameters. Created by the query handler for one
 * request, handed to customizers ({@see DoctrineQueryHandler::withCustomizer()}), count strategies and compilers;
 * never stored in a service.
 *
 * Parameters are named `p1`, `p2`, ... in compilation order, so the DQL only varies with *which* filters are active.
 */
final class DoctrineQueryContext implements QueryContextInterface
{
    private int $parameterCount = 0;

    private bool $distinct = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueryBuilder $queryBuilder,
        private readonly JoinRegistry $joins,
        public readonly ListMapping $mapping,
        public readonly Dialect $dialect,
        private readonly JsonSupport $json,
    ) {
    }

    public function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function queryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function joins(): JoinRegistry
    {
        return $this->joins;
    }

    public function rootAlias(): string
    {
        return $this->joins->rootAlias();
    }

    /** The DQL path of the root identifier (`root.id`). */
    public function identifierPath(): string
    {
        return $this->rootAlias().'.'.$this->mapping->identifier;
    }

    /**
     * Binds a value and returns its placeholder (`:p3`). `$type` is a DBAL type name, a binding type or null.
     */
    public function parameter(mixed $value, string|ArrayParameterType|null $type = null): string
    {
        $name = 'p'.++$this->parameterCount;
        $this->queryBuilder->setParameter($name, $value, $type);

        return ':'.$name;
    }

    /**
     * Declares that the query can return a root several times (a customizer joining a collection): the total
     * becomes `COUNT(DISTINCT id)` and the page is fetched through the ORM paginator.
     */
    public function requireDistinct(): void
    {
        $this->distinct = true;
    }

    public function isDistinct(): bool
    {
        return $this->distinct || $this->joins->requiresDistinct();
    }

    /**
     * Whether a path through collections is joined instead of one EXISTS per criterion: the criterion's filter has
     * `provider_options.doctrine.join: shared` (`$join`), or the path goes through a named join with `shared: true`.
     */
    public function isShared(ResolvedField $field, string $join = 'exists'): bool
    {
        return 'shared' === $join || array_any($field->steps, static fn (ResolvedStep $step): bool => $step->isSharedJoin());
    }

    /**
     * A `provider_options.doctrine` option of the filter that emitted the criterion (`case_insensitive`, `join`).
     */
    public function option(FieldCriterion $criterion, string $option, mixed $default): mixed
    {
        return $criterion->providerOptions[DoctrineQueryHandlerFactory::PROVIDER][$option] ?? $default;
    }

    /**
     * The DQL expression of a JSON path field on the alias of its entity.
     *
     * @throws \LogicException when the platform has no JSON path compiler, or cannot realise the cast
     */
    public function jsonColumn(ResolvedField $field, string $column): string
    {
        $compiler = $this->json->compiler ?? throw new \LogicException($this->json->refusal ?? 'JSON paths are not available.');
        if (null !== ($reason = $this->json->castRefusal($field->field->cast))) {
            throw new \LogicException(\sprintf('The JSON path "%s" cannot be compiled: %s.', $field->field, $reason));
        }

        return $compiler->compile($column, $field->field->json, $field->field->cast);
    }

    /**
     * The column as it must be compared for exactness: on MySQL and MariaDB a string column goes through
     * `SIDUS_BINARY()` (the column collation is case- and accent-insensitive and pads with spaces), anywhere else
     * it is returned as is. A foreign key read through its association (`root.forum`) is not a state field path: DQL
     * only accepts it inside `IDENTITY()`, which the function needs.
     */
    public function exact(ResolvedField $field, string $column): string
    {
        $field = $field->collapseIdentifier(); // as {@see FieldAccess::matching()} does before building the column
        if (!$this->dialect->isMySqlFamily() || !\in_array($field->valueType(), FieldCapabilities::TEXT_TYPES, true)) {
            return $column;
        }

        return DqlFunctions::BINARY.'('.($field->leafIsForeignKey ? 'IDENTITY('.$column.')' : $column).')';
    }
}
