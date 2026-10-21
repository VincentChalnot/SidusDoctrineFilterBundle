<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `cache:clear` in a non-debug kernel (DoctrineBundle registers its optional metadata warmer, priority 1000): the list
 * warmer validates against the Doctrine metadata during the kernel boot, the optional warmers then run on the same
 * container and DoctrineMetadataCacheWarmer refuses to run when the metadata is already loaded.
 */
final class CacheClearTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $filters
     */
    private function kernel(array $filters): TestKernel
    {
        return TestKernel::create(
            ['posts' => ['provider' => 'doctrine', 'options' => ['entity' => Post::class], 'filters' => $filters]],
            null,
            ['debug' => false],
        );
    }

    public function testCacheClearRunsTheOptionalDoctrineMetadataWarmerAfterTheListWarmer(): void
    {
        $kernel = $this->kernel(['title' => ['type' => 'text'], 'author' => ['type' => 'text', 'attributes' => ['author.name']]]);
        $cacheDir = $kernel->getCacheDir();

        try {
            $tester = new CommandTester(new Application($kernel)->find('cache:clear'));

            self::assertSame(0, $tester->execute([]), $tester->getDisplay());
            self::assertFileExists($cacheDir.'/doctrine/orm/default_metadata.php', 'the optional metadata warmer ran');
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove(\dirname($cacheDir));
        }
    }

    public function testCacheClearOnAWarmCacheRunsTheOptionalDoctrineMetadataWarmerAfterTheListWarmer(): void
    {
        $kernel = $this->kernel(['title' => ['type' => 'text']]);
        $cacheDir = $kernel->getCacheDir();
        $requestTime = $_SERVER['REQUEST_TIME'];

        try {
            $application = new Application($kernel);
            $application->find('cache:warmup');
            // the container was built after the request started: cache:clear takes its "fresh" shortcut. Pretend the
            // cache predates the command (the usual deploy), which reboots the kernel on a new warmup directory.
            $_SERVER['REQUEST_TIME'] = time() + 3600;
            $tester = new CommandTester($application->find('cache:clear'));

            self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        } finally {
            $_SERVER['REQUEST_TIME'] = $requestTime;
            $kernel->shutdown();
            new Filesystem()->remove(\dirname($cacheDir));
        }
    }

    public function testCacheClearStillFailsOnAMisconfiguredList(): void
    {
        $kernel = $this->kernel(['author' => ['type' => 'text', 'attributes' => ['author.nam']]]);
        $cacheDir = $kernel->getCacheDir();

        try {
            $this->expectException(\Sidus\FilterBundle\Exception\ConfigurationException::class);
            $this->expectExceptionMessage('List "posts", filter "author" (type "text"), attribute "author.nam"');

            new Application($kernel)->find('cache:clear');
        } finally {
            new Filesystem()->remove(\dirname($cacheDir));
        }
    }
}
