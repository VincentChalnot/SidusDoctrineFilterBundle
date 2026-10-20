<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\DoctrineFilterBundle\Tests\Support\FixtureLoader;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Provider\CountStrategyInterface;
use Sidus\FilterBundle\Provider\QueryHandlerInterface;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Sidus\FilterBundle\Test\Contract\AbstractProviderContractTestCase;

/**
 * The shared provider contract, run against a real database: the kernel is booted and the fixture loaded once per
 * class (the cases only read).
 */
abstract class AbstractDoctrineContractTestCase extends AbstractProviderContractTestCase
{
    private static ?TestKernel $kernel = null;

    abstract protected static function usePostgres(): bool;

    public static function setUpBeforeClass(): void
    {
        if (static::usePostgres() && false === getenv('SIDUS_FILTER_PG_DSN')) {
            return;
        }
        self::$kernel = TestKernel::create([], null, ['postgres' => static::usePostgres()]);
        self::$kernel->boot();
        $em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        FixtureLoader::load($em);
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    protected function setUp(): void
    {
        if (null === self::$kernel) {
            self::markTestSkipped('SIDUS_FILTER_PG_DSN is not set.');
        }
    }

    protected function providerName(): string
    {
        return DoctrineQueryHandlerFactory::PROVIDER;
    }

    protected function listOptions(): array
    {
        return ['entity' => Post::class, 'identifier' => 'id'];
    }

    protected function createHandler(ListDefinition $definition, ?CountStrategyInterface $countStrategy = null): QueryHandlerInterface
    {
        $factory = self::$kernel->getContainer()->get('test.service_container')->get(DoctrineQueryHandlerFactory::class);
        \assert($factory instanceof DoctrineQueryHandlerFactory);

        return $factory->createQueryHandler($definition, FilterTypeRegistry::builtIn(), $countStrategy);
    }
}
