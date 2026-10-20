<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Contract;

use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;

/**
 * The contract on MySQL or MariaDB (skipped unless SIDUS_FILTER_MYSQL_DSN is set). The database is expected to use
 * its default collation (case- and accent-insensitive), which is what the dialect has to defeat.
 */
final class DoctrineMysqlProviderTest extends AbstractDoctrineContractTestCase
{
    protected static function platform(): string
    {
        return 'mysql';
    }

    protected function listOptions(): array
    {
        return ['entity' => Post::class, 'identifier' => 'id', 'binary_sort' => true];
    }
}
