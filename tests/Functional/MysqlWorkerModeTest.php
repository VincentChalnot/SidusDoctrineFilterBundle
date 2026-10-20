<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

final class MysqlWorkerModeTest extends AbstractWorkerModeTestCase
{
    protected static function platform(): string
    {
        return 'mysql';
    }
}
