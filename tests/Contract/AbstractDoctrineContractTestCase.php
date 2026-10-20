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

    /** @return 'sqlite'|'pgsql'|'mysql' */
    abstract protected static function platform(): string;

    /** The environment variable holding the DSN of the database, null for SQLite. */
    protected static function dsnVariable(): ?string
    {
        return match (static::platform()) {
            'pgsql' => 'SIDUS_FILTER_PG_DSN',
            'mysql' => 'SIDUS_FILTER_MYSQL_DSN',
            default => null,
        };
    }

    public static function setUpBeforeClass(): void
    {
        $variable = static::dsnVariable();
        if (null !== $variable && false === getenv($variable)) {
            return;
        }
        self::$kernel = TestKernel::create([], null, ['platform' => static::platform()]);
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
            self::markTestSkipped(static::dsnVariable().' is not set.');
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

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<int>
     */
    private function ids(array $filters): array
    {
        $result = $this->createHandler($this->definition())->handle(\Sidus\FilterBundle\Input\ListInput::fromArray(['filters' => $filters]));

        return array_map($this->itemId(...), $result->items());
    }

    /**
     * MySQL and MariaDB compare strings with a case- and accent-insensitive collation that pads with spaces; the
     * reference does none of it. These pass on every platform, so they stay honest on the others too.
     */
    public function testStringEqualityIsExact(): void
    {
        $equals = static fn (string $value): array => ['title' => ['operator' => 'equals', 'value' => $value]];

        self::assertSame([2], $this->ids($equals('hello again')));
        self::assertSame([], $this->ids($equals('HELLO AGAIN')), 'case');
        self::assertSame([], $this->ids($equals('ecole')), 'accents');
        self::assertSame([], $this->ids($equals('école')), 'accents and case');
        self::assertSame([5], $this->ids($equals('ÉCOLE')));
    }

    public function testASpaceIsNotEmptyAndNotEqualToTheEmptyString(): void
    {
        $connection = self::$kernel->getContainer()->get('doctrine.orm.entity_manager')->getConnection();
        $connection->executeStatement("UPDATE post SET summary = ' ' WHERE id = 2");

        try {
            self::assertSame([3], $this->ids(['summary' => ['operator' => 'is_empty']]), 'post 2 has a space now: not empty');
            self::assertSame([1, 2, 4, 5, 6], $this->ids(['summary' => ['operator' => 'is_not_empty']]));
        } finally {
            $connection->executeStatement("UPDATE post SET summary = '' WHERE id = 2");
        }
    }
}
