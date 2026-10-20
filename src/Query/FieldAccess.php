<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Sidus\DoctrineFilterBundle\Compiler\DqlExpression;
use Sidus\DoctrineFilterBundle\Metadata\ResolvedField;

/**
 * Turns "this predicate on that field" into DQL, whatever the path:
 *
 *  - a to-one path (or a scalar): the shared LEFT JOINs of the {@see JoinRegistry}, the predicate on the column;
 *  - a path through a to-many association: one correlated EXISTS subquery for this criterion alone, with its own
 *    aliases (`x1_tags`, numbered in compilation order). Two criteria on the same collection are therefore
 *    independent ("any element"), no row is multiplied, and paging stays a plain LIMIT/OFFSET.
 *
 * A named join of cardinality many correlates through its condition (`FROM Entity x1_alias WHERE x1_alias.field = <equals>`).
 * The subquery selects from the target entity correlated through the foreign key when the collection is mapped by
 * one (`FROM Tag x1_tags WHERE x1_tags.post = post_`), and otherwise from the source entity joined to the collection
 * (`FROM Post x1_r JOIN x1_r.tags x1_tags WHERE x1_r = post_`).
 */
final class FieldAccess
{
    /**
     * @param \Closure(string): DqlExpression $predicate receives the column expression
     * @param bool                            $emptyListMatches whether an empty fan-out also satisfies the predicate (is null / is empty on a path through a collection: "no element" counts as empty)
     */
    public static function matching(DoctrineQueryContext $context, ResolvedField $field, \Closure $predicate, bool $emptyListMatches = false): DqlExpression
    {
        $first = $field->firstToMany();
        $shared = null !== $first && $context->isShared($field);
        if (null === $first || $shared) {
            $alias = $context->joins()->alias($field, \count($field->steps), $shared);

            return $predicate(self::column($field, $alias, $context));
        }

        $exists = DqlExpression::safe('EXISTS ('.self::subquery($context, $field, $first, static fn (): string => '1', $predicate).')');
        if (!$emptyListMatches) {
            return $exists;
        }

        return DqlExpression::safe('(NOT EXISTS ('.self::subquery($context, $field, $first, static fn (): string => '1', null).') OR '.$exists->dql.')');
    }

    /**
     * "The collection reached by this path has no element": `NOT EXISTS`, for a field ending on a to-many association.
     */
    public static function none(DoctrineQueryContext $context, ResolvedField $field): DqlExpression
    {
        $first = $field->firstToMany() ?? throw new \LogicException(\sprintf('"%s" is not a to-many path.', $field));

        return DqlExpression::safe('NOT EXISTS ('.self::subquery($context, $field, $first, static fn (): string => '1', null).')');
    }

    /**
     * A scalar subquery aggregating over a to-many path (`COUNT`, `MIN`, `MAX`), correlated with the root row.
     *
     * @param 'count'|'min'|'max' $aggregate
     */
    public static function aggregate(DoctrineQueryContext $context, ResolvedField $field, string $aggregate): string
    {
        $first = $field->firstToMany() ?? throw new \LogicException(\sprintf('"%s" is not a to-many path.', $field));

        return '('.self::subquery(
            $context,
            $field,
            $first,
            static fn (string $alias, string $column): string => strtoupper($aggregate).'('.($field->endsOnAssociation() ? $alias : $column).')',
            null,
        ).')';
    }

    /**
     * The DQL expression of the terminal field on the alias of its entity.
     */
    public static function column(ResolvedField $field, string $alias, DoctrineQueryContext $context): string
    {
        if (null === $field->leaf) {
            throw new \LogicException(\sprintf('"%s" ends on an association: it has no column.', $field));
        }

        $column = $alias.'.'.$field->leaf;

        return $field->isJson() ? $context->jsonColumn($field, $column) : $column;
    }

    /**
     * @param \Closure(string, string): string $select receives the last alias and the column expression (null leaf: the alias)
     * @param (\Closure(string): DqlExpression)|null $predicate
     */
    private static function subquery(DoctrineQueryContext $context, ResolvedField $field, int $first, \Closure $select, ?\Closure $predicate): string
    {
        $joins = $context->joins();
        $index = $joins->nextExistsIndex();
        $base = $joins->alias($field, $first);
        $step = $field->steps[$first];
        $prefix = 'x'.$index.'_';
        $alias = $prefix.$step->name;
        if (null !== $step->join) {
            $from = $step->target.' '.$alias;
            $where = [$alias.'.'.$step->join->onField.' = '.$joins->onExpression($step->join)];
        } elseif (null !== $step->mappedBy) {
            $from = $step->target.' '.$alias;
            $where = [$alias.'.'.$step->mappedBy.' = '.$base];
        } else {
            $source = $prefix.'r';
            $from = $step->source.' '.$source.' JOIN '.$source.'.'.$step->name.' '.$alias;
            $where = [$source.' = '.$base];
        }
        $joinsDql = '';
        for ($i = $first + 1, $count = \count($field->steps); $i < $count; ++$i) {
            $next = $field->steps[$i];
            $joinsDql .= ($next->toMany ? ' JOIN ' : ' LEFT JOIN ').$alias.'.'.$next->name.' '.($alias .= '_'.$next->name);
        }
        $column = null === $field->leaf ? $alias : self::column($field, $alias, $context);
        if (null !== $predicate) {
            $where[] = $predicate($column)->dql;
        }

        return 'SELECT '.$select($alias, $column).' FROM '.$from.$joinsDql.' WHERE '.implode(' AND ', $where);
    }
}
