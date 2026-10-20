<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Sidus\DoctrineFilterBundle\SidusDoctrineFilterBundle;
use Sidus\DoctrineFilterBundle\Tests\Support\SqliteDriver;
use Sidus\FilterBundle\SidusFilterBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
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
        private readonly bool $usePostgres = false,
    ) {
        parent::__construct('test', true);
    }

    /**
     * @param array<string, array<string, mixed>>        $lists
     * @param \Closure(ContainerConfigurator): void|null $services
     * @param array<string, mixed>                       $doctrineOrm extra `doctrine.orm` configuration
     */
    public static function create(array $lists, ?\Closure $services = null, ?string $id = null, bool $usePostgres = false, array $doctrineOrm = []): self
    {
        return new self(static function (ContainerConfigurator $container) use ($lists, $services, $doctrineOrm): void {
            $container->extension('sidus_filter', ['lists' => $lists]);
            if ([] !== $doctrineOrm) {
                $container->extension('doctrine', ['orm' => $doctrineOrm]);
            }
            if (null !== $services) {
                $services($container);
            }
        }, $id ?? uniqid('k', true), $usePostgres);
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
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false]);
        $container->extension('doctrine', [
            'dbal' => ['connections' => ['default' => $this->usePostgres
                ? ['url' => (string) getenv('SIDUS_FILTER_PG_DSN'), 'logging' => false, 'profiling' => false]
                : ['driver' => 'pdo_sqlite', 'driver_class' => SqliteDriver::class, 'memory' => true, 'logging' => false, 'profiling' => false]]],
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
