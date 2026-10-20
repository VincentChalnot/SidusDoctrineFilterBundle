<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;

/**
 * SQLite driver for the tests: opens the connection with `Pdo\Sqlite` (the PHP 8.4 driver subclass), which is the
 * only non-deprecated way to register user functions such as REGEXP (SQLite has no built-in one).
 */
final class SqliteDriver extends AbstractSQLiteDriver
{
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        $pdo = \Pdo\Sqlite::connect('sqlite:'.(isset($params['memory']) ? ':memory:' : $params['path']));
        // Registered by hand until the bundle ships its own helper.
        $pdo->createFunction('REGEXP', static fn (?string $pattern, ?string $value): ?int => null === $pattern || null === $value ? null : (int) (1 === preg_match('~'.str_replace('~', '\~', $pattern).'~u', $value)), 2, \Pdo\Sqlite::DETERMINISTIC);

        return new PDOConnection($pdo);
    }
}
