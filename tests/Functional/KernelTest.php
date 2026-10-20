<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Support\FixtureLoader;

final class KernelTest extends TestCase
{
    public function testTheKernelBootsOnSqliteAndLoadsTheContractFixture(): void
    {
        $kernel = TestKernel::create([]);
        $kernel->boot();

        try {
            $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            FixtureLoader::load($em);

            self::assertSame(6, $em->getRepository(Post::class)->count([]));
            self::assertInstanceOf(SQLitePlatform::class, $em->getConnection()->getDatabasePlatform());
        } finally {
            $kernel->shutdown();
        }
    }
}
