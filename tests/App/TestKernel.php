<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Sidus\DoctrineFilterBundle\CacheWarmer\ListWarmer;
use Sidus\DoctrineFilterBundle\SidusDoctrineFilterBundle;
use Sidus\DoctrineFilterBundle\Tests\Support\RecordingLogger;
use Sidus\DoctrineFilterBundle\Tests\Support\SqliteDriver;
use Sidus\FilterBundle\SidusFilterBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Boots both bundles on the test entities, with SQLite in memory (or PostgreSQL when SIDUS_FILTER_PG_DSN is set), the
 * lists and services given by the test, in an isolated cache directory wiped before the first boot.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param \Closure(ContainerConfigurator): void|null $configure
     */
    public function __construct(
        private readonly ?\Closure $configure = null,
        private readonly string $id = 'default',
        private readonly string $platform = 'sqlite',
        private readonly bool $recordQueries = false,
        private readonly bool $warmer = true,
    ) {
        parent::__construct('test', true);
    }

    /**
     * @param array<string, array<string, mixed>>        $lists
     * @param \Closure(ContainerConfigurator): void|null $services
     * @param array{id?: string, platform?: 'sqlite'|'pgsql'|'mysql', record_queries?: bool, warmer?: bool} $options `record_queries` logs every
     *                                                                                          SQL statement into {@see RecordingLogger}; `warmer`
     *                                                                                          false removes the list warmer (which runs on boot)
     */
    public static function create(array $lists, ?\Closure $services = null, array $options = []): self
    {
        $record = $options['record_queries'] ?? false;

        return new self(static function (ContainerConfigurator $container) use ($lists, $services, $record): void {
            $container->extension('sidus_filter', ['lists' => $lists]);
            if ($record) {
                $container->services()->set('logger', RecordingLogger::class)->public();
            }
            if (null !== $services) {
                $services($container);
            }
        }, $options['id'] ?? uniqid('k', true), $options['platform'] ?? 'sqlite', $record, $options['warmer'] ?? true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SidusFilterBundle();
        yield new SidusDoctrineFilterBundle();
    }

    public function boot(): void
    {
        if (!$this->booted) {
            new Filesystem()->remove($this->getCacheDir());
        }
        parent::boot();
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->warmer) {
            return;
        }
        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(ListWarmer::class)->clearTag('kernel.cache_warmer');
            }
        });
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/sidus-doctrine-filter-bundle-tests/'.$this->id.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/sidus-doctrine-filter-bundle-tests/'.$this->id.'/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'form' => ['enabled' => true, 'csrf_protection' => ['enabled' => false]], 'csrf_protection' => false]);
        $connection = match ($this->platform) {
            'pgsql' => ['url' => (string) getenv('SIDUS_FILTER_PG_DSN')],
            'mysql' => ['url' => (string) getenv('SIDUS_FILTER_MYSQL_DSN')],
            default => ['driver' => 'pdo_sqlite', 'driver_class' => SqliteDriver::class, 'memory' => true],
        };
        $container->extension('doctrine', [
            'dbal' => ['connections' => ['default' => $connection + ['logging' => $this->recordQueries, 'profiling' => false]]],
            'orm' => ['mappings' => ['Fixtures' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => \dirname(__DIR__).'/Fixtures/Entity',
                'prefix' => 'Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity',
            ]]],
        ]);
        if (null !== $this->configure) {
            ($this->configure)($container);
        }
    }
}
