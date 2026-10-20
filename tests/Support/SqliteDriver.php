<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Sidus\DoctrineFilterBundle\Platform\SqliteRegexp;

/**
 * SQLite driver for the tests: opens the connection with `Pdo\Sqlite` (the PHP 8.4 driver subclass), which is the
 * only non-deprecated way to register user functions such as REGEXP (SQLite has no built-in one: see {@see SqliteRegexp}).
 */
final class SqliteDriver extends AbstractSQLiteDriver
{
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        $pdo = \Pdo\Sqlite::connect('sqlite:'.(isset($params['memory']) ? ':memory:' : $params['path']));
        SqliteRegexp::register($pdo);

        return new PDOConnection($pdo);
    }
}
