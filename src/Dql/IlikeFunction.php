<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;

/**
 * `SIDUS_ILIKE(subject, pattern) = 1`: PostgreSQL `ILIKE` with `!` as escape character. PostgreSQL only.
 */
class IlikeFunction extends AbstractBooleanFunction
{
    protected function test(SqlWalker $sqlWalker, string $subject, string $pattern): string
    {
        if (!$sqlWalker->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw QueryException::syntaxError('SIDUS_ILIKE is available on PostgreSQL only.');
        }

        return $subject.' ILIKE '.$pattern." ESCAPE '!'";
    }
}
