<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Contract;

use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;

/**
 * The contract on PostgreSQL (skipped unless SIDUS_FILTER_PG_DSN is set).
 */
final class DoctrinePostgresProviderTest extends AbstractDoctrineContractTestCase
{
    protected static function platform(): string
    {
        return 'pgsql';
    }

    protected function listOptions(): array
    {
        return ['entity' => Post::class, 'identifier' => 'id', 'binary_sort' => true];
    }
}
