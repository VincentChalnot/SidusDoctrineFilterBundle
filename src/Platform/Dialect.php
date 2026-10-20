<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Platform;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Sidus\FilterBundle\Exception\ConfigurationException;

/**
 * The database platforms the provider guarantees the reference semantics on (strict mode: no approximation on a
 * platform it cannot verify). MySQL and MariaDB compare strings with case-insensitive collations by default, so
 * `Equals` would silently diverge: they are refused rather than approximated.
 */
final readonly class Dialect
{
    private function __construct(private string $name)
    {
    }

    public static function of(AbstractPlatform $platform): self
    {
        return match (true) {
            $platform instanceof PostgreSQLPlatform => new self('PostgreSQL'),
            $platform instanceof SQLitePlatform => new self('SQLite'),
            default => throw new ConfigurationException(\sprintf('The doctrine provider supports PostgreSQL and SQLite, not %s.', $platform::class)),
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

    public function name(): string
    {
        return $this->name;
    }
}
