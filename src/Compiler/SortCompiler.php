<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\DoctrineFilterBundle\Query\Ordering;
use Sidus\FilterBundle\Criterion\Sort;
use Sidus\FilterBundle\Criterion\SortDirection;

/**
 * One ORDER BY for the whole request, built from the sort columns then the identifier as final tie-breaker (always
 * ascending, pages are stable). Nulls first ascending, last descending, on every platform: a nullable column is
 * preceded by a `CASE WHEN column IS NULL` key (DQL has no NULLS FIRST). A to-many column is sorted through an
 * aggregate declared in the column's `provider_options.doctrine.aggregate` (hidden select), anything else is refused.
 * With the list option `binary_sort`, strings are compared byte-wise like the reference (PostgreSQL `COLLATE "C"`).
 */
final class SortCompiler implements DoctrineSortCompilerInterface
{
    public static function getProvider(): string
    {
        return DoctrineQueryHandlerFactory::PROVIDER;
    }

    public static function getCriterionClass(): string
    {
        return Sort::class;
    }

    public function apply(Sort $sort, DoctrineQueryContext $context): void
    {
        $queryBuilder = $context->queryBuilder();
        foreach ($sort->columns as $index => $column) {
            $field = $context->mapping->fields->resolve($column->field);
            $aggregate = $column->providerOptions[DoctrineQueryHandlerFactory::PROVIDER]['aggregate'] ?? null;
            if (null !== ($reason = FieldCapabilities::sortRefusal($field, $aggregate))) {
                throw new \LogicException(\sprintf('The doctrine provider cannot sort on "%s": %s.', $column->field, $reason));
            }

            $descending = SortDirection::Desc === $column->direction;
            if (null !== $aggregate) {
                $alias = 'sort_'.($index + 1);
                $queryBuilder->addSelect(FieldAccess::aggregate($context, $field, $aggregate).' AS HIDDEN '.$alias);
                $expression = $alias;
                $nullable = 'count' !== $aggregate;
                $text = false;
            } else {
                $expression = FieldAccess::column($field, $context->joins()->alias($field, \count($field->steps)), $context);
                $nullable = $field->isNullable();
                $text = \in_array($field->leafType, FieldCapabilities::TEXT_TYPES, true);
            }
            if ($nullable) {
                Ordering::add($queryBuilder, 'CASE WHEN '.$expression.' IS NULL THEN '.($descending ? 1 : 0).' ELSE '.($descending ? 0 : 1).' END');
            }
            $binary = $text && $context->mapping->binarySort;
            Ordering::add($queryBuilder, $binary ? DqlFunctions::BINARY.'('.$expression.')' : $expression, $descending);
        }
        Ordering::add($queryBuilder, $context->identifierPath());
    }
}
