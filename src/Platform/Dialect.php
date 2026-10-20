<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Platform;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Sidus\FilterBundle\Exception\ConfigurationException;

/**
 * The database platforms the provider realises the reference semantics on: PostgreSQL, SQLite, MariaDB and MySQL.
 * Anything else is refused rather than approximated (strict mode).
 *
 * What differs, and how the compilers react:
 *  - MySQL and MariaDB compare strings with the column collation, by default case- and accent-insensitive and
 *    ignoring trailing spaces (`PAD SPACE`): string equality, `LIKE`, `LOCATE` and `ORDER BY` go through
 *    `SIDUS_BINARY()` (`BINARY x`) so that they are exact and byte-wise like the reference;
 *  - SQLite's `LIKE` ignores ASCII case and its `LOWER()` folds ASCII only; it has no `REGEXP` function;
 *  - PostgreSQL follows the database collation for `ORDER BY` (opt in to byte-wise with `binary_sort`).
 */
final readonly class Dialect
{
    private function __construct(private string $name)
    {
    }

    public static function of(AbstractPlatform $platform): self
    {
        return self::tryFrom($platform)
            ?? throw new ConfigurationException(\sprintf('The doctrine provider supports PostgreSQL, SQLite, MariaDB and MySQL, not %s.', $platform::class));
    }

    public static function tryFrom(AbstractPlatform $platform): ?self
    {
        return match (true) {
            $platform instanceof PostgreSQLPlatform => new self('PostgreSQL'),
            $platform instanceof SQLitePlatform => new self('SQLite'),
            $platform instanceof MariaDBPlatform => new self('MariaDB'),
            $platform instanceof AbstractMySQLPlatform => new self('MySQL'),
            default => null,
        };
    }

    public function isPostgreSql(): bool
    {
        return 'PostgreSQL' === $this->name;
    }

    public function isSqlite(): bool
    {
        return 'SQLite' === $this->name;
    }

    /** MariaDB or MySQL. */
    public function isMySqlFamily(): bool
    {
        return 'MariaDB' === $this->name || 'MySQL' === $this->name;
    }

    public function isMariaDb(): bool
    {
        return 'MariaDB' === $this->name;
    }

    /**
     * Whether `NULL` already sorts first ascending and last descending (MySQL and MariaDB), as the reference wants:
     * no null-ordering key is needed.
     */
    public function nullsSortLikeReference(): bool
    {
        return $this->isMySqlFamily();
    }

    public function name(): string
    {
        return $this->name;
    }
}
