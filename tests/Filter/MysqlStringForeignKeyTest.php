<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

final class MysqlStringForeignKeyTest extends AbstractStringForeignKeyTestCase
{
    protected static function platform(): string
    {
        return 'mysql';
    }
}
