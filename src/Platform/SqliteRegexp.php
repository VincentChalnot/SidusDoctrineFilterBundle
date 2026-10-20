<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Platform;

use Sidus\FilterBundle\Criterion\PosixRegex;

/**
 * SQLite has no built-in REGEXP: the `Matches` criterion needs a user function on the connection. This registers the
 * one the provider expects, with the reference semantics ({@see PosixRegex}: the pattern is a POSIX ERE subset, a
 * leading `(?i)` means case-insensitive, null never matches).
 *
 * Call it when the connection is opened, for example from a DBAL driver middleware.
 */
final class SqliteRegexp
{
    public static function register(\Pdo\Sqlite $connection): void
    {
        $connection->createFunction('REGEXP', self::match(...), 2, \Pdo\Sqlite::DETERMINISTIC);
    }

    public static function match(?string $pattern, ?string $subject): ?int
    {
        if (null === $pattern || null === $subject) {
            return null;
        }
        $caseSensitive = !str_starts_with($pattern, '(?i)');
        if (!$caseSensitive) {
            $pattern = substr($pattern, 4);
        }

        return PosixRegex::matches($pattern, $caseSensitive, $subject) ? 1 : 0;
    }
}
