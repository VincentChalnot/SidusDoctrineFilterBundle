<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;

/**
 * `SIDUS_REGEXP(subject, pattern) = 1`: unanchored POSIX ERE match, case-sensitive. PostgreSQL `~`; SQLite `REGEXP`
 * (a user function, see {@see \Sidus\DoctrineFilterBundle\Platform\SqliteRegexp}).
 */
class RegexpFunction extends AbstractBooleanFunction
{
    protected function test(SqlWalker $sqlWalker, string $subject, string $pattern): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => $subject.' ~ '.$pattern,
            $platform instanceof SQLitePlatform => $subject.' REGEXP '.$pattern,
            default => throw QueryException::syntaxError('SIDUS_REGEXP supports PostgreSQL and SQLite only.'),
        };
    }
}
