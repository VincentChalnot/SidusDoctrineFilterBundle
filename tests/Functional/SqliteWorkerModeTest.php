<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

final class SqliteWorkerModeTest extends AbstractWorkerModeTestCase
{
    protected static function platform(): string
    {
        return 'sqlite';
    }
}
