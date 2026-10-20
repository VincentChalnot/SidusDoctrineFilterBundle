<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle;

use Sidus\DoctrineFilterBundle\Compiler\DoctrineCriterionCompilerInterface;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineSortCompilerInterface;
use Sidus\DoctrineFilterBundle\Query\JsonPathCompilerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Doctrine ORM provider (`provider: doctrine`) of sidus/filter-bundle.
 */
final class SidusDoctrineFilterBundle extends AbstractBundle
{
    public const string COMPILER_TAG = 'sidus_doctrine_filter.compiler';
    public const string JSON_COMPILER_TAG = 'sidus_doctrine_filter.json_compiler';

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(DoctrineCriterionCompilerInterface::class)->addTag(self::COMPILER_TAG);
        $builder->registerForAutoconfiguration(DoctrineSortCompilerInterface::class)->addTag(self::COMPILER_TAG);
        $builder->registerForAutoconfiguration(JsonPathCompilerInterface::class)->addTag(self::JSON_COMPILER_TAG);
    }
}
