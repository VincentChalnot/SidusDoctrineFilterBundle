<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Filter;

final class SqliteRelationQueryTest extends AbstractRelationQueryTestCase
{
    protected static function platform(): string
    {
        return 'sqlite';
    }
}
