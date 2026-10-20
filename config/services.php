<?php

declare(strict_types=1);

use Sidus\DoctrineFilterBundle\CacheWarmer\ListWarmer;
use Sidus\DoctrineFilterBundle\Command\LintCommand;
use Sidus\DoctrineFilterBundle\Compiler\AllOfCompiler;
use Sidus\DoctrineFilterBundle\Compiler\AnyOfCompiler;
use Sidus\DoctrineFilterBundle\Compiler\ContainsCompiler;
use Sidus\DoctrineFilterBundle\Compiler\EndsWithCompiler;
use Sidus\DoctrineFilterBundle\Compiler\EqualsCompiler;
use Sidus\DoctrineFilterBundle\Compiler\InCompiler;
use Sidus\DoctrineFilterBundle\Compiler\IsEmptyCompiler;
use Sidus\DoctrineFilterBundle\Compiler\IsNullCompiler;
use Sidus\DoctrineFilterBundle\Compiler\MatchesCompiler;
use Sidus\DoctrineFilterBundle\Compiler\NativeCompiler;
use Sidus\DoctrineFilterBundle\Compiler\NotCompiler;
use Sidus\DoctrineFilterBundle\Compiler\RangeCompiler;
use Sidus\DoctrineFilterBundle\Compiler\SortCompiler;
use Sidus\DoctrineFilterBundle\Compiler\StartsWithCompiler;
use Sidus\DoctrineFilterBundle\Factory\DoctrineQueryHandlerFactory;
use Sidus\DoctrineFilterBundle\Filter\RelationFilterType;
use Sidus\DoctrineFilterBundle\Form\RelationMapper;
use Sidus\DoctrineFilterBundle\Json\MySqlJsonPathCompiler;
use Sidus\DoctrineFilterBundle\Json\PostgreSqlJsonPathCompiler;
use Sidus\DoctrineFilterBundle\Validation\ListValidator;
use Sidus\FilterBundle\Registry\FilterTypeRegistry;
use Sidus\FilterBundle\Registry\ListDefinitionRegistry;
use Sidus\FilterBundle\Form\FormFilterMapperInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autoconfigure();

    foreach ([
        EqualsCompiler::class, InCompiler::class, ContainsCompiler::class, StartsWithCompiler::class, EndsWithCompiler::class,
        RangeCompiler::class, IsNullCompiler::class, IsEmptyCompiler::class, MatchesCompiler::class, NotCompiler::class,
        AnyOfCompiler::class, AllOfCompiler::class, NativeCompiler::class, SortCompiler::class,
    ] as $compiler) {
        $services->set($compiler);
    }

    $services->set(RelationFilterType::class);
    if (interface_exists(FormFilterMapperInterface::class) && class_exists(EntityType::class)) {
        $services->set(RelationMapper::class)->args([service('doctrine')]);
    }

    $services->set(PostgreSqlJsonPathCompiler::class);
    $services->set(MySqlJsonPathCompiler::class);

    $services->set(DoctrineQueryHandlerFactory::class)->args([
        service('doctrine'),
        tagged_iterator('sidus_doctrine_filter.compiler'),
        tagged_iterator('sidus_doctrine_filter.json_compiler'),
    ]);

    $services->set(ListValidator::class)->args([
        service(DoctrineQueryHandlerFactory::class),
        service('doctrine'),
        service(FilterTypeRegistry::class),
    ]);
    $services->set(ListWarmer::class)->public()->args([
        service(ListDefinitionRegistry::class),
        service(ListValidator::class),
    ])->tag('kernel.cache_warmer');

    if (class_exists(Command::class)) {
        $services->set(LintCommand::class)->args([
            service(ListDefinitionRegistry::class),
            service(ListValidator::class),
        ]);
    }
};
