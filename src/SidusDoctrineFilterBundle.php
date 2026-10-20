<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle;

use Sidus\DoctrineFilterBundle\Compiler\DoctrineCriterionCompilerInterface;
use Sidus\DoctrineFilterBundle\Compiler\DoctrineSortCompilerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Doctrine ORM provider (`provider: doctrine`) of sidus/filter-bundle.
 */
final class SidusDoctrineFilterBundle extends AbstractBundle
{
    public const string COMPILER_TAG = 'sidus_doctrine_filter.compiler';

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $builder->registerForAutoconfiguration(DoctrineCriterionCompilerInterface::class)->addTag(self::COMPILER_TAG);
        $builder->registerForAutoconfiguration(DoctrineSortCompilerInterface::class)->addTag(self::COMPILER_TAG);
    }
}
