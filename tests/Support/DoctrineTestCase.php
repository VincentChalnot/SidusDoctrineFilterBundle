<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryContext;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryHandler;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Psr\Container\ContainerInterface;
use Sidus\DoctrineFilterBundle\Filter\RelationFilterType;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Input\ListInput;
use Sidus\FilterBundle\Filter\Type\BuiltInFilterTypes;
use Sidus\FilterBundle\Filter\Type\NativeFilterType;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Sidus\FilterBundle\Result\ListResult;
use Sidus\FilterBundle\Test\Contract\FixtureDataset;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A booted kernel (SQLite, contract fixture loaded) shared by the tests of a class, with helpers to build lists
 * from the contract's filter specs and to capture the DQL a request produced.
 */
abstract class DoctrineTestCase extends TestCase
{
    protected static ?TestKernel $kernel = null;

    /**
     * @param array<string, mixed> $options
     */
    protected static function bootKernel(array $options = []): void
    {
        self::$kernel = TestKernel::create([], null, $options);
        self::$kernel->boot();
        FixtureLoader::load(static::entityManager());
    }

    /** @return 'sqlite'|'pgsql'|'mysql' */
    protected static function platform(): string
    {
        return 'sqlite';
    }

    public static function setUpBeforeClass(): void
    {
        $variable = ['pgsql' => 'SIDUS_FILTER_PG_DSN', 'mysql' => 'SIDUS_FILTER_MYSQL_DSN'][static::platform()] ?? null;
        if (null !== $variable && false === getenv($variable)) {
            return;
        }
        static::bootKernel(['platform' => static::platform()] + static::kernelOptions());
    }

    protected function setUp(): void
    {
        if (null === self::$kernel) {
            self::markTestSkipped('The database of the platform "'.static::platform().'" is not configured (SIDUS_FILTER_PG_DSN / SIDUS_FILTER_MYSQL_DSN).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function kernelOptions(): array
    {
        return [];
    }

    protected static function entityManager(): EntityManagerInterface
    {
        $em = self::$kernel?->getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * The core's filter types plus the provider's `relation` type.
     */
    protected static function registry(?ContainerInterface $nativeBuilders = null): FilterTypeRegistry
    {
        $types = [];
        foreach (BuiltInFilterTypes::classes() as $class) {
            $types[] = NativeFilterType::class === $class ? new NativeFilterType($nativeBuilders) : new $class();
        }

        return new FilterTypeRegistry([...$types, new RelationFilterType()]);
    }

    protected static function factory(): DoctrineQueryHandlerFactory
    {
        $factory = self::$kernel?->getContainer()->get('test.service_container')->get(DoctrineQueryHandlerFactory::class);
        \assert($factory instanceof DoctrineQueryHandlerFactory);

        return $factory;
    }

    /**
     * A list on the Post entity from the contract's filter specs (key => spec), only those given.
     *
     * @param list<string>|array<string, array<string, mixed>> $specs   spec keys of {@see FixtureDataset::filterSpecs()} to keep, or full specs by key
     * @param array<string, mixed>                              $options list options (`entity` is set)
     */
    protected static function list(array $specs, array $options = [], string $code = 'probe', string $count = ListDefinition::COUNT_AUTO, ?FilterTypeRegistry $types = null): ListDefinition
    {
        $available = FixtureDataset::filterSpecs();
        $types ??= self::registry();
        $filters = [];
        foreach ($specs as $key => $spec) {
            if (\is_int($key)) {
                $key = $spec;
                $spec = $available[$key];
            }
            $resolver = new OptionsResolver();
            $types->get($spec['type'])->configureOptions($resolver);
            $filters[] = new FilterDefinition(
                key: (string) $key,
                type: $spec['type'],
                attributes: $spec['attributes'] ?? [],
                options: $resolver->resolve($spec['options'] ?? []),
                providerOptions: $spec['provider_options'] ?? [],
                locked: $spec['locked'] ?? false,
                default: $spec['default'] ?? null,
            );
        }

        return new ListDefinition($code, DoctrineQueryHandlerFactory::PROVIDER, ['entity' => Post::class, 'identifier' => 'id', ...$options], 10, 50, $count, $filters);
    }

    /**
     * Runs one request and captures what it produced.
     *
     * @param array<string, mixed>                       $input
     * @param \Closure(DoctrineQueryContext): void|null $customizer
     */
    protected static function execute(ListDefinition $list, array $input = [], ?\Closure $customizer = null, bool $readTotal = false, ?FilterTypeRegistry $types = null): Probe
    {
        $captured = null;
        $handler = self::factory()->createQueryHandler($list, $types ?? self::registry(), null);
        \assert($handler instanceof DoctrineQueryHandler);
        $handler = $handler->withCustomizer(static function (DoctrineQueryContext $context) use (&$captured, $customizer): void {
            $captured = $context;
            if (null !== $customizer) {
                $customizer($context);
            }
        });
        $result = $handler->handle(ListInput::fromArray($input));
        $total = $readTotal ? $result->total() : null;
        \assert($captured instanceof DoctrineQueryContext);

        return new Probe($captured, $result, $total);
    }
}
