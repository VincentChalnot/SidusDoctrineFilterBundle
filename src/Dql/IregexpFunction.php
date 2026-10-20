<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;

/**
 * `SIDUS_IREGEXP(subject, pattern) = 1`: the case-insensitive variant. PostgreSQL `~*`; SQLite gets the pattern
 * prefixed with `(?i)`, which the registered REGEXP function understands.
 */
class IregexpFunction extends AbstractBooleanFunction
{
    protected function test(SqlWalker $sqlWalker, string $subject, string $pattern): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => $subject.' ~* '.$pattern,
            $platform instanceof SQLitePlatform => $subject." REGEXP ('(?i)' || ".$pattern.')',
            default => throw QueryException::syntaxError('SIDUS_IREGEXP supports PostgreSQL and SQLite only.'),
        };
    }
}
