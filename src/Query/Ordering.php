<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Query;

use Doctrine\ORM\QueryBuilder;

/**
 * `QueryBuilder::addOrderBy()` across ORM 3.x: since 3.7 the direction is a `SortDirection` enum and a string is
 * deprecated, before it is a string.
 */
final class Ordering
{
    private static ?bool $enum = null;

    public static function add(QueryBuilder $queryBuilder, string $expression, bool $descending = false): void
    {
        self::$enum ??= self::acceptsEnum();
        if (self::$enum) {
            $queryBuilder->addOrderBy($expression, $descending ? \SortDirection::Descending : \SortDirection::Ascending);

            return;
        }
        $queryBuilder->addOrderBy($expression, $descending ? 'DESC' : 'ASC');
    }

    private static function acceptsEnum(): bool
    {
        $type = new \ReflectionMethod(QueryBuilder::class, 'addOrderBy')->getParameters()[1]->getType();

        return null !== $type && str_contains((string) $type, 'SortDirection');
    }
}
