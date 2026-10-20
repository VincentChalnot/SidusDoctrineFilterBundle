<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

final class PostgresWorkerModeTest extends AbstractWorkerModeTestCase
{
    protected static function platform(): string
    {
        return 'pgsql';
    }
}
