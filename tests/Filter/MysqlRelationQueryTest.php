<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

final class MysqlRelationQueryTest extends AbstractRelationQueryTestCase
{
    protected static function platform(): string
    {
        return 'mysql';
    }
}
