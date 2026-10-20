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
use Sidus\DoctrineFilterBundle\Platform\Dialect;
use Sidus\DoctrineFilterBundle\Query\DoctrineQueryHandler;
use Sidus\FilterBundle\Definition\Field;
use Sidus\FilterBundle\Definition\ListDefinition;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Sidus\FilterBundle\Provider\CountStrategyInterface;
use Sidus\FilterBundle\Provider\QueryHandlerFactoryInterface;
use Sidus\FilterBundle\Provider\QueryHandlerInterface;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The `doctrine` provider: a container service (immutable and shared) that creates one single-use
 * {@see DoctrineQueryHandler} per request.
 *
 * List options: `entity` (required), `identifier` (tie-breaker and COUNT field, default: the single identifier
 * of the entity), `binary_sort` (compare strings byte-wise like the reference, PostgreSQL `COLLATE "C"`, default
 * false: the database collation), `joins` (named joins).
 *
 * Filter options (`provider_options.doctrine`): `case_insensitive` (`lower`, default: `LOWER()` on both sides, or
 * `ilike`, PostgreSQL only).
 */
final class DoctrineQueryHandlerFactory implements QueryHandlerFactoryInterface
{
    public const string PROVIDER = 'doctrine';
    public const string ROOT_ALIAS = 'root';

    /** @var \WeakMap<ListDefinition, ListMapping> */
    private \WeakMap $mappings;

    private readonly DoctrineCompiler $compiler;

    /**
     * @param iterable<DoctrineCriterionCompilerInterface|DoctrineSortCompilerInterface> $compilers
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        iterable $compilers,
    ) {
        $this->compiler = new DoctrineCompiler($compilers);
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
    }

    public static function configureFilterOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['case_insensitive' => 'lower']);
        $resolver->setAllowedValues('case_insensitive', ['lower', 'ilike']);
    }

    public function createQueryHandler(ListDefinition $definition, FilterTypeRegistry $filterTypes, ?CountStrategyInterface $countStrategy): QueryHandlerInterface
    {
        $entity = $definition->options['entity'];
        $manager = $this->registry->getManagerForClass($entity);
        if (!$manager instanceof EntityManagerInterface) {
            throw new ConfigurationException(\sprintf('List "%s": %s is not managed by a Doctrine ORM entity manager.', $definition->code, $entity));
        }
        DqlFunctions::register($manager->getConfiguration());
        $dialect = Dialect::of($manager->getConnection()->getDatabasePlatform());
        $mapping = $this->mapping($definition);
        if (!$dialect->isPostgreSql() && \in_array('ilike', array_column($mapping->fieldOptions, 'case_insensitive'), true)) {
            throw new ConfigurationException(\sprintf('List "%s": case_insensitive "ilike" is available on PostgreSQL only, the connection is %s.', $definition->code, $dialect->name()));
        }

        return new DoctrineQueryHandler($definition, $filterTypes, $countStrategy, $manager, $this->compiler, $mapping, $dialect);
    }

    /**
     * Resolved once per list definition (immutable and shared by the registry), memoised by definition instance.
     */
    public function mapping(ListDefinition $definition): ListMapping
    {
        if (!isset($this->mappings[$definition])) {
            $resolver = new FieldResolver($this->registry, $definition->options['entity']);
            $this->mappings[$definition] = new ListMapping(
                $definition->options['entity'],
                $definition->options['identifier'] ?? $resolver->identifier(),
                $resolver,
                $definition->options['binary_sort'] ?? false,
                self::fieldOptions($definition),
            );
        }

        return $this->mappings[$definition];
    }

    /**
     * The `provider_options.doctrine` of the filters, by attribute. A criterion carries a field, not the filter
     * that emitted it, so two filters declaring different options for one field are ambiguous: refused.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fieldOptions(ListDefinition $definition): array
    {
        $options = [];
        foreach ($definition->filters as $filter) {
            $filterOptions = $filter->getProviderOptions(self::PROVIDER);
            if ([] === $filterOptions) {
                continue;
            }
            foreach ($filter->attributes as $field) {
                \assert($field instanceof Field);
                $key = (string) $field;
                if (isset($options[$key]) && $options[$key] !== $filterOptions) {
                    throw new ConfigurationException(\sprintf('List "%s": several filters declare different provider_options.doctrine for the field "%s".', $definition->code, $key));
                }
                $options[$key] = $filterOptions;
            }
        }

        return $options;
    }
}
