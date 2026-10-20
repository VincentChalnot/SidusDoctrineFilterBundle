<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Joins;

final class PostgresNamedJoinTest extends AbstractNamedJoinTestCase
{
    protected static function platform(): string
    {
        return 'pgsql';
    }
}
