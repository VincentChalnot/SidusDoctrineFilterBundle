<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Compiler;

use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Metadata\FieldCapabilities;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\FieldAccess;
use Sidus\DoctrineFilterBundle\Query\Ordering;
use Doctrine\ORM\QueryBuilder;
use Sidus\FilterBundle\Criterion\Sort;
use Sidus\FilterBundle\Criterion\SortDirection;

/**
 * One ORDER BY for the whole request, built from the sort columns then the identifier as final tie-breaker (always
 * ascending, pages are stable). Nulls first ascending, last descending, on every platform: a nullable column is
 * preceded by a `CASE WHEN column IS NULL` key (DQL has no NULLS FIRST), except on MySQL and MariaDB where NULL
 * already sorts that way. A to-many column is sorted through an
 * aggregate declared in the column's `provider_options.doctrine.aggregate` (hidden select), anything else is refused.
 * A column may instead declare `provider_options.doctrine.expression` ({@see ExpressionSort}): computed order keys.
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
            $options = $column->providerOptions[DoctrineQueryHandlerFactory::PROVIDER] ?? [];
            $aggregate = $options['aggregate'] ?? null;
            $expression = $options['expression'] ?? null;
            if (null !== $expression && null !== $aggregate) {
                throw new \LogicException(\sprintf('The doctrine provider cannot sort on "%s": aggregate and expression are exclusive.', $column->field));
            }
            $descending = SortDirection::Desc === $column->direction;

            if (null !== $expression) {
                foreach (ExpressionSort::keys($expression) as $key => ['expression' => $dql, 'direction' => $direction]) {
                    $alias = 'sort_'.($index + 1).'_'.($key + 1);
                    $queryBuilder->addSelect('('.ExpressionSort::dql($dql, $context->rootAlias()).') AS HIDDEN '.$alias);
                    $this->order($queryBuilder, $context, $alias, '('.ExpressionSort::dql($dql, $context->rootAlias()).')', 'same' === $direction ? $descending : 'desc' === $direction, true, false);
                }
                continue;
            }

            if (null !== ($reason = FieldCapabilities::sortRefusal($field, $aggregate))) {
                throw new \LogicException(\sprintf('The doctrine provider cannot sort on "%s": %s.', $column->field, $reason));
            }
            if (null !== $aggregate) {
                $alias = 'sort_'.($index + 1);
                $queryBuilder->addSelect(FieldAccess::aggregate($context, $field, $aggregate).' AS HIDDEN '.$alias);
                if ('count' !== $aggregate && !$context->dialect->nullsSortLikeReference()) {
                    // 1 when NULL: first ascending, last descending (a bare alias: PostgreSQL refuses one inside an expression)
                    $queryBuilder->addSelect(FieldAccess::aggregate($context, $field, $aggregate, true).' AS HIDDEN '.$alias.'_null');
                    Ordering::add($queryBuilder, $alias.'_null', !$descending);
                }
                $this->order($queryBuilder, $context, $alias, $alias, $descending, false, false);
            } else {
                $column = FieldAccess::column($field, $context->joins()->alias($field, \count($field->steps)), $context);
                $this->order(
                    $queryBuilder,
                    $context,
                    $column,
                    $column,
                    $descending,
                    $field->isNullable(),
                    \in_array($field->valueType(), FieldCapabilities::TEXT_TYPES, true),
                );
            }
        }
        Ordering::add($queryBuilder, $context->identifierPath());
    }

    /**
     * @param string $expression the sort value as ORDER BY refers to it (a hidden select alias for computed values)
     * @param string $value      the same value written out: PostgreSQL does not allow an output alias inside an
     *                           expression of ORDER BY, so the null-ordering key repeats it
     */
    private function order(QueryBuilder $queryBuilder, DoctrineQueryContext $context, string $expression, string $value, bool $descending, bool $nullable, bool $text): void
    {
        if ($nullable && !$context->dialect->nullsSortLikeReference()) {
            if ($value === $expression) {
                Ordering::add($queryBuilder, 'CASE WHEN '.$value.' IS NULL THEN '.($descending ? 1 : 0).' ELSE '.($descending ? 0 : 1).' END');
            } else {
                // 1 for NULL: first when ascending (nulls first), last when descending
                Ordering::add($queryBuilder, DqlFunctions::IS_NULL.'('.$value.')', !$descending);
            }
        }
        $binary = $text && $context->mapping->binarySort;
        Ordering::add($queryBuilder, $binary ? DqlFunctions::BINARY.'('.$expression.')' : $expression, $descending);
    }
}
