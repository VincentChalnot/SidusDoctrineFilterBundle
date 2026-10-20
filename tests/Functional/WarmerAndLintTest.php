<?php

declare(strict_types=1);

namespace Sidus\DoctrineFilterBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Sidus\DoctrineFilterBundle\CacheWarmer\ListWarmer;
use Sidus\DoctrineFilterBundle\Tests\App\TestKernel;
use Sidus\DoctrineFilterBundle\Tests\Fixtures\Entity\Post;
use Sidus\FilterBundle\Exception\ConfigurationException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A bad field path fails `cache:warmup` and `lint:sidus-filter`, a good configuration passes both.
 */
final class WarmerAndLintTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $filters
     */
    private function boot(array $filters, bool $warmer = true): TestKernel
    {
        $kernel = TestKernel::create([
            'posts' => ['provider' => 'doctrine', 'options' => ['entity' => Post::class], 'filters' => $filters],
            'other' => ['provider' => 'in_memory', 'options' => ['source' => 'nope'], 'filters' => ['bogus' => ['type' => 'text', 'attributes' => ['whatever.path']]]],
        ], null, ['warmer' => $warmer]);
        $kernel->boot();

        return $kernel;
    }

    public function testAValidConfigurationWarmsUp(): void
    {
        $kernel = $this->boot(['title' => ['type' => 'text'], 'author' => ['type' => 'text', 'attributes' => ['author.name']]]);

        try {
            self::assertSame([], $kernel->getContainer()->get('test.service_container')->get(ListWarmer::class)->warmUp($kernel->getCacheDir()));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testABadPathFailsTheBootWhichWarmsTheCacheAndNamesEverything(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('List "posts", filter "author" (type "text"), attribute "author.nam"');

        $this->boot(['title' => ['type' => 'text'], 'author' => ['type' => 'text', 'attributes' => ['author.nam']], 'views' => ['type' => 'text']]);
    }

    public function testTheWarmerIsNotOptional(): void
    {
        $kernel = $this->boot(['title' => ['type' => 'text']]);

        try {
            self::assertFalse($kernel->getContainer()->get('test.service_container')->get(ListWarmer::class)->isOptional());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheLintCommandPassesOnAValidConfiguration(): void
    {
        $kernel = $this->boot(['title' => ['type' => 'text']]);

        try {
            $tester = new CommandTester(new Application($kernel)->find('lint:sidus-filter'));

            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('1 doctrine list(s) match the Doctrine metadata', $tester->getDisplay(), 'the in_memory list is not linted');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheLintCommandFailsOnABadPath(): void
    {
        $kernel = $this->boot(['author' => ['type' => 'text', 'attributes' => ['author.nam']]], warmer: false);

        try {
            $tester = new CommandTester(new Application($kernel)->find('lint:sidus-filter'));

            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('1 problem(s) in 1 doctrine list(s)', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
            self::assertStringContainsString('attribute "author.nam"', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
            self::assertSame(1, $tester->execute(['code' => 'posts']));
            self::assertSame(1, $tester->execute(['code' => 'unknown']));
        } finally {
            $kernel->shutdown();
        }
    }
}
