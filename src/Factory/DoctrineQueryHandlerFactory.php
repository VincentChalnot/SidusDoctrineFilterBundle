<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Factory;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineCompiler;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineCriterionCompilerInterface;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineSortCompilerInterface;
use Sidus\DoctrineFilterBundle\Dql\DqlFunctions;
use Sidus\DoctrineFilterBundle\Metadata\FieldResolver;
use Sidus\DoctrineFilterBundle\Metadata\ListMapping;
use Sidus\DoctrineFilterBundle\Metadata\NamedJoin;
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryHandler;
use Sidus\DoctrineFilterBundle\Query\JsonPathCompilerInterface;
use Sidus\DoctrineFilterBundle\Query\JsonSupport;
use Sidus\FilterBundle\Definition\FilterDefinition;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Provider\CountStrategyInterface;
use Sidus\FilterBundle\Provider\QueryHandlerFactoryInterface;
use Sidus\FilterBundle\Provider\QueryHandlerInterface;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The `doctrine` provider: a container service (immutable and shared) that creates one single-use
 * {@see DoctrineQueryHandler} per request.
 *
 * List options: `entity` (required), `identifier` (tie-breaker and COUNT field, default: the single identifier
 * of the entity), `binary_sort` (compare strings byte-wise like the reference, default false: the database
 * collation), `joins` (named joins: `{entity, on: {field, equals}, cardinality: one|many, shared}`).
 *
 * Filter options (`provider_options.doctrine`): `case_insensitive` (`lower`, default: `LOWER()` on both sides, or
 * `ilike`, PostgreSQL only), `join` (`exists`, default, or `shared`: join the collections of the attributes instead of
 * one EXISTS per criterion, the same element then satisfies every criterion on that path).
 */
final class DoctrineQueryHandlerFactory implements QueryHandlerFactoryInterface
{
    public const string PROVIDER = 'doctrine';
    public const string ROOT_ALIAS = 'root';

    /** @var \WeakMap<ListDefinition, ListMapping> */
    private \WeakMap $mappings;

    private readonly DoctrineCompiler $compiler;

    /** @var list<JsonPathCompilerInterface> */
    private readonly array $jsonCompilers;

    /**
     * @param iterable<DoctrineCriterionCompilerInterface|DoctrineSortCompilerInterface> $compilers
     * @param iterable<JsonPathCompilerInterface>                                        $jsonCompilers
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        iterable $compilers,
        iterable $jsonCompilers = [],
    ) {
        $this->compiler = new DoctrineCompiler($compilers);
        $this->jsonCompilers = [...$jsonCompilers];
        $this->mappings = new \WeakMap();
    }

    public static function getProvider(): string
    {
        return self::PROVIDER;
    }

    public static function configureListOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('entity');
        $resolver->setAllowedTypes('entity', 'string');
        $resolver->setDefaults(['identifier' => null, 'binary_sort' => false, 'joins' => []]);
        $resolver->setAllowedTypes('identifier', ['string', 'null']);
        $resolver->setAllowedTypes('binary_sort', 'bool');
        $resolver->setAllowedTypes('joins', 'array');
        $resolver->setNormalizer('joins', static fn (Options $options, array $joins): array => NamedJoin::normalize($joins));
    }

    public static function configureFilterOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['case_insensitive' => 'lower', 'join' => 'exists']);
        $resolver->setAllowedValues('case_insensitive', ['lower', 'ilike']);
        $resolver->setAllowedValues('join', ['exists', 'shared']);
    }

    public function createQueryHandler(ListDefinition $definition, FilterTypeRegistry $filterTypes, ?CountStrategyInterface $countStrategy): QueryHandlerInterface
    {
        $entity = $definition->options['entity'];
        $manager = $this->registry->getManagerForClass($entity);
        if (!$manager instanceof EntityManagerInterface) {
            throw new ConfigurationException(\sprintf('List "%s": %s is not managed by a Doctrine ORM entity manager.', $definition->code, $entity));
        }
        $dialect = Dialect::of($manager->getConnection()->getDatabasePlatform());
        $json = $this->prepare($manager, $dialect);
        $mapping = $this->mapping($definition);
        if (!$dialect->isPostgreSql() && array_any($definition->filters, static fn (FilterDefinition $filter): bool => 'ilike' === ($filter->getProviderOptions(self::PROVIDER)['case_insensitive'] ?? null))) {
            throw new ConfigurationException(\sprintf('List "%s": case_insensitive "ilike" is available on PostgreSQL only, the connection is %s.', $definition->code, $dialect->name()));
        }

        return new DoctrineQueryHandler($definition, $filterTypes, $countStrategy, $manager, $this->compiler, $mapping, $dialect, $json);
    }

    /**
     * Registers the DQL functions the compilers emit on the entity manager (idempotent) and returns the JSON support
     * of the dialect.
     */
    public function prepare(EntityManagerInterface $manager, Dialect $dialect): JsonSupport
    {
        DqlFunctions::register($manager->getConfiguration());
        $json = $this->jsonSupport($dialect);
        $json->compiler?->registerFunctions($manager->getConfiguration());

        return $json;
    }

    /**
     * The JSON path compiler of the dialect, or the reason there is none (no compiler for the platform, or its
     * library is missing).
     */
    public function jsonSupport(Dialect $dialect): JsonSupport
    {
        foreach ($this->jsonCompilers as $compiler) {
            if ($compiler->supports($dialect)) {
                $unavailable = $compiler->unavailable();

                return null === $unavailable ? new JsonSupport($compiler) : JsonSupport::none($unavailable);
            }
        }

        return JsonSupport::none(\sprintf('JSON paths are not available on %s: no JSON path compiler supports it.', $dialect->name()));
    }

    /**
     * Resolved once per list definition (immutable and shared by the registry), memoised by definition instance.
     */
    public function mapping(ListDefinition $definition): ListMapping
    {
        if (!isset($this->mappings[$definition])) {
            $joins = [];
            foreach ($definition->options['joins'] ?? [] as $alias => $config) {
                $joins[(string) $alias] = NamedJoin::fromConfig((string) $alias, $config);
            }
            $resolver = new FieldResolver($this->registry, $definition->options['entity'], $joins);
            $resolver->validateJoins();
            $this->mappings[$definition] = new ListMapping(
                $definition->options['entity'],
                $definition->options['identifier'] ?? $resolver->identifier(),
                $resolver,
                $definition->options['binary_sort'] ?? false,
            );
        }

        return $this->mappings[$definition];
    }
}
