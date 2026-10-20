<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Dql;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\SqlWalker;

/**
 * `SIDUS_REGEXP(subject, pattern) = 1`: unanchored POSIX ERE match, case-sensitive. PostgreSQL `~`; SQLite `REGEXP`
 * (a user function, see {@see \Sidus\DoctrineFilterBundle\Platform\SqliteRegexp}); MariaDB `REGEXP` (PCRE) with `(?-i)`;
 * MySQL `REGEXP_LIKE(..., 'c')`.
 */
class RegexpFunction extends AbstractBooleanFunction
{
    protected function test(SqlWalker $sqlWalker, string $subject, string $pattern): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => $subject.' ~ '.$pattern,
            $platform instanceof SQLitePlatform => $subject.' REGEXP '.$pattern,
            // PCRE with the collation's case folding: `(?-i)` makes it case-sensitive whatever the collation.
            $platform instanceof MariaDBPlatform => $subject." REGEXP CONCAT('(?-i)', ".$pattern.')',
            $platform instanceof AbstractMySQLPlatform => 'REGEXP_LIKE('.$subject.', '.$pattern.", 'c')",
            default => throw QueryException::syntaxError('SIDUS_REGEXP supports PostgreSQL, SQLite, MariaDB and MySQL only.'),
        };
    }
}
